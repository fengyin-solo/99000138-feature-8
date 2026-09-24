<?php
session_start();

/**
 * 返回JSON响应
 */
function jsonResponse($code, $msg, $data = null) {
    header('Content-Type: application/json; charset=utf-8');
    $res = ['code' => $code, 'msg' => $msg];
    if ($data !== null) $res['data'] = $data;
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 获取留言类型文字
 */
function getTypeLabel($type) {
    $map = ['help' => '居民求助', 'suggest' => '意见建议', 'lost' => '失物招领'];
    return $map[$type] ?? '其他';
}

/**
 * 获取类型图标
 */
function getTypeIcon($type) {
    $map = ['help' => '🆘', 'suggest' => '💡', 'lost' => '🔍'];
    return $map[$type] ?? '📌';
}

/**
 * 获取状态文字
 */
function getStatusLabel($status) {
    $map = [0 => '待审核', 1 => '已通过', 2 => '已拒绝'];
    return $map[$status] ?? '未知';
}

/**
 * 获取状态样式类
 */
function getStatusClass($status) {
    $map = [0 => 'pending', 1 => 'approved', 2 => 'rejected'];
    return $map[$status] ?? '';
}

/**
 * 时间格式化
 */
function timeAgo($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    if ($diff->y > 0) return $diff->y . '年前';
    if ($diff->m > 0) return $diff->m . '个月前';
    if ($diff->d > 0) return $diff->d . '天前';
    if ($diff->h > 0) return $diff->h . '小时前';
    if ($diff->i > 0) return $diff->i . '分钟前';
    return '刚刚';
}

/**
 * 检查管理员登录
 */
function requireAdmin() {
    if (empty($_SESSION['admin_id'])) {
        header('Location: login.php');
        exit;
    }
}

/**
 * 过滤输入
 */
function cleanInput($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

/**
 * 获取访客唯一标识
 * 基于session和cookie实现匿名用户标识
 */
function getVisitorId() {
    if (empty($_SESSION['visitor_id'])) {
        if (!empty($_COOKIE['visitor_id'])) {
            $_SESSION['visitor_id'] = $_COOKIE['visitor_id'];
        } else {
            $visitorId = md5(uniqid('visitor_', true) . $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_USER_AGENT']);
            $_SESSION['visitor_id'] = $visitorId;
            setcookie('visitor_id', $visitorId, time() + 86400 * 365, '/');
        }
    }
    return $_SESSION['visitor_id'];
}

/**
 * 检查留言是否已收藏
 */
function isFavorited($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 获取当前访客收藏的所有留言ID
 */
function getFavoritedMessageIds() {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT message_id FROM favorites WHERE visitor_id = ?");
    $stmt->execute([$visitorId]);
    return array_column($stmt->fetchAll(), 'message_id');
}

/**
 * 切换收藏状态
 * 返回: ['favorited' => bool, 'action' => 'add'|'remove']
 */
function toggleFavorite($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();

    $db->beginTransaction();
    try {
        $stmt = $db->prepare("SELECT id FROM favorites WHERE visitor_id = ? AND message_id = ? FOR UPDATE");
        $stmt->execute([$visitorId, $messageId]);
        $exists = $stmt->fetch();

        if ($exists) {
            $db->prepare("DELETE FROM favorites WHERE visitor_id = ? AND message_id = ?")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => false, 'action' => 'remove'];
        } else {
            $db->prepare("INSERT INTO favorites (visitor_id, message_id) VALUES (?, ?)")
                ->execute([$visitorId, $messageId]);
            $result = ['favorited' => true, 'action' => 'add'];
        }

        $db->commit();
        return $result;
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * 获取举报类型文字
 */
function getReportTypeLabel($type) {
    $map = [
        'spam' => '垃圾信息',
        'abuse' => '辱骂攻击',
        'illegal' => '违法违规',
        'porn' => '色情低俗',
        'other' => '其他'
    ];
    return $map[$type] ?? '未知';
}

/**
 * 获取举报状态文字
 */
function getReportStatusLabel($status) {
    $map = [
        0 => '待处理',
        1 => '已处理-已删除',
        2 => '已处理-已忽略',
        3 => '已驳回'
    ];
    return $map[$status] ?? '未知';
}

/**
 * 获取举报状态样式类
 */
function getReportStatusClass($status) {
    $map = [
        0 => 'pending',
        1 => 'resolved-deleted',
        2 => 'resolved-ignored',
        3 => 'rejected'
    ];
    return $map[$status] ?? '';
}

/**
 * 检查当前访客是否已举报过某条留言
 */
function hasReported($messageId) {
    $visitorId = getVisitorId();
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM reports WHERE visitor_id = ? AND message_id = ?");
    $stmt->execute([$visitorId, $messageId]);
    return $stmt->fetch() !== false;
}

/**
 * 提交举报
 */
function submitReport($messageId, $reportType, $description = '') {
    $visitorId = getVisitorId();
    $db = getDB();

    $validTypes = ['spam', 'abuse', 'illegal', 'porn', 'other'];
    if (!in_array($reportType, $validTypes)) {
        throw new Exception('无效的举报类型');
    }

    $stmt = $db->prepare("SELECT id FROM messages WHERE id = ? AND status = 1");
    $stmt->execute([$messageId]);
    if (!$stmt->fetch()) {
        throw new Exception('留言不存在或未通过审核');
    }

    if (hasReported($messageId)) {
        throw new Exception('您已经举报过这条留言了');
    }

    $stmt = $db->prepare("INSERT INTO reports (message_id, visitor_id, report_type, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([$messageId, $visitorId, $reportType, $description]);

    return $db->lastInsertId();
}

/**
 * 获取待处理举报数量
 */
function getPendingReportCount() {
    $db = getDB();
    return $db->query("SELECT COUNT(*) FROM reports WHERE status = 0")->fetchColumn();
}

/* ===================== 首页组合筛选与摘要视图 ===================== */

/**
 * 首页类型可选项（键为数据库中的 type 值，'' 表示全部）
 */
function homeTypeOptions() {
    return [
        ''       => '全部',
        'help'   => '🆘 求助',
        'suggest' => '💡 建议',
        'lost'   => '🔍 失物招领',
    ];
}

/**
 * 时间范围可选项（键为天数，'' 表示不限时间）
 */
function homeRangeOptions() {
    return [
        ''   => '全部时间',
        '7'  => '近 7 天',
        '30' => '近 30 天',
        '90' => '近 90 天',
        '365' => '近一年',
    ];
}

/**
 * 排序可选项
 */
function homeSortOptions() {
    return [
        'time' => '🕐 按时间',
        'hot'  => '🔥 按热度',
    ];
}

/**
 * 展示视图可选项
 */
function homeViewOptions() {
    return [
        'list'    => '📄 列表',
        'summary' => '📊 摘要',
    ];
}

/**
 * 将任意输入规范化为合法的首页筛选条件
 */
function normalizeHomeFilters(array $raw) {
    $type = isset($raw['type']) && in_array($raw['type'], ['help', 'suggest', 'lost'], true)
        ? $raw['type'] : '';
    $range = isset($raw['range']) && array_key_exists((string)$raw['range'], homeRangeOptions())
        ? (string)$raw['range'] : '';
    $sort = (isset($raw['sort']) && $raw['sort'] === 'hot') ? 'hot' : 'time';
    $view = (isset($raw['view']) && $raw['view'] === 'summary') ? 'summary' : 'list';
    return compact('type', 'range', 'sort', 'view');
}

/**
 * 判断筛选条件是否为默认值
 */
function isDefaultHomeFilters(array $filters) {
    return $filters === normalizeHomeFilters([]);
}

/**
 * 把筛选条件写入 Cookie，供切换页面/回到首页后恢复
 */
function persistHomeFilters(array $filters) {
    $payload = json_encode($filters, JSON_UNESCAPED_UNICODE);
    if ($payload !== false) {
        setcookie('home_filters', $payload, [
            'expires'  => time() + 86400 * 30,
            'path'     => '/',
            'samesite' => 'Lax',
        ]);
    }
}

/**
 * 清除首页筛选条件记忆
 */
function clearHomeFilters() {
    setcookie('home_filters', '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'samesite' => 'Lax',
    ]);
}

/**
 * 解析首页筛选条件：
 * - URL 显式带条件参数时以 URL 为准（兼容旧链接，缺省维度取默认值），并更新记忆
 * - 仅翻页或不带任何条件参数时，从 Cookie 恢复上次的组合条件
 * - ?reset=1 清除记忆并回到默认条件
 */
function resolveHomeFilters() {
    if (isset($_GET['reset'])) {
        clearHomeFilters();
        header('Location: index.php');
        exit;
    }

    $explicitKeys = ['type', 'range', 'sort', 'view'];
    $hasExplicit = false;
    foreach ($explicitKeys as $key) {
        if (isset($_GET[$key])) {
            $hasExplicit = true;
            break;
        }
    }

    if ($hasExplicit) {
        $filters = normalizeHomeFilters($_GET);
        persistHomeFilters($filters);
        return $filters;
    }

    if (!empty($_COOKIE['home_filters'])) {
        $saved = json_decode($_COOKIE['home_filters'], true);
        if (is_array($saved)) {
            return normalizeHomeFilters($saved);
        }
    }

    return normalizeHomeFilters([]);
}

/**
 * 基于筛选条件构造留言查询（列表、总数、摘要共用，保证结果口径一致）
 * 返回 [WHERE 子句, 绑定参数, ORDER BY 子句]
 */
function buildHomeMessageQuery(array $filters) {
    $where = 'WHERE status = 1';
    $params = [];

    if ($filters['type'] !== '') {
        $where .= ' AND type = ?';
        $params[] = $filters['type'];
    }

    if ($filters['range'] !== '') {
        // 范围值已通过白名单校验，天数为安全整数字符串
        $days = (int)$filters['range'];
        $where .= " AND created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)";
    }

    $orderBy = $filters['sort'] === 'hot'
        ? 'views DESC, created_at DESC'
        : 'created_at DESC';

    return [$where, $params, $orderBy];
}

/**
 * 生成携带当前组合条件的首页链接
 * @param bool $explicit 筛选控件链接需显式表达全部维度（即使为默认值），
 *                       以便与“仅翻页/回到首页时沿用记忆”区分开
 */
function homeFilterUrl(array $filters, array $overrides = [], $explicit = false) {
    $params = array_merge(
        ['type' => '', 'range' => '', 'sort' => 'time', 'view' => 'list', 'page' => 1],
        $filters,
        $overrides
    );

    $query = [];
    if ((int)$params['page'] > 1) {
        $query['page'] = (int)$params['page'];
    }
    if ($params['type'] !== '' || $explicit) {
        $query['type'] = $params['type'];
    }
    if ($params['range'] !== '' || $explicit) {
        $query['range'] = $params['range'];
    }
    if ($params['sort'] === 'hot' || $explicit) {
        $query['sort'] = $params['sort'];
    }
    if ($params['view'] === 'summary' || $explicit) {
        $query['view'] = $params['view'];
    }

    return 'index.php' . (empty($query) ? '' : '?' . http_build_query($query));
}
