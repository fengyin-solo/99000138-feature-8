<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = '社区便民留言板 - 首页';
$currentPage = 'home';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

$db = getDB();

// ===== 组合筛选条件：类型 + 时间范围 + 排序 =====
$validTypes = ['help', 'suggest', 'lost'];
$validRanges = ['all', 'today', '7d', '30d'];
$validSorts = ['time', 'hot'];

// 恢复默认筛选
if (isset($_GET['reset'])) {
    unset($_SESSION['home_filter']);
    header('Location: index.php');
    exit;
}

// 显式传参优先；未传参时回退到本次会话保存的条件，实现跨页面保留
$savedFilter = $_SESSION['home_filter'] ?? [];

$type = $_GET['type'] ?? ($savedFilter['type'] ?? '');
if (!in_array($type, $validTypes, true)) {
    $type = '';
}

$range = $_GET['range'] ?? ($savedFilter['range'] ?? 'all');
if (!in_array($range, $validRanges, true)) {
    $range = 'all';
}

$sort = $_GET['sort'] ?? ($savedFilter['sort'] ?? 'time');
if (!in_array($sort, $validSorts, true)) {
    $sort = 'time';
}

// 记住当前组合条件，切换页面或回到首页后仍保留
$_SESSION['home_filter'] = ['type' => $type, 'range' => $range, 'sort' => $sort];

$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 10;

// 构建查询
$where = "WHERE status = 1";
$params = [];

if ($type !== '') {
    $where .= " AND type = ?";
    $params[] = $type;
}

$rangeStart = getRangeStartTime($range);
if ($rangeStart !== null) {
    $where .= " AND created_at >= ?";
    $params[] = $rangeStart;
}

// 排序
$orderBy = ($sort === 'hot') ? "views DESC, created_at DESC" : "created_at DESC";

// 结果摘要统计（与列表共用同一查询条件，保证列表、摘要、页数一致）
$summaryStmt = $db->prepare("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN type='help' THEN 1 ELSE 0 END) as help_count,
    SUM(CASE WHEN type='suggest' THEN 1 ELSE 0 END) as suggest_count,
    SUM(CASE WHEN type='lost' THEN 1 ELSE 0 END) as lost_count
    FROM messages $where");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch();

$total = (int)$summary['total'];
$totalPages = max(1, (int)ceil($total / $pageSize));

// 页码超出范围时跳转到最后一页，避免列表与摘要、分页对不上
if ($page > $totalPages) {
    header('Location: index.php?' . buildFilterQuery($type, $range, $sort, $totalPages));
    exit;
}

$offset = ($page - 1) * $pageSize;

// 列表
$sql = "SELECT id, nickname, type, title, content, image, views, created_at FROM messages $where ORDER BY $orderBy LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

// 是否有生效的筛选条件（用于空态提示与清除入口）
$hasActiveFilter = ($type !== '' || $range !== 'all');

// 获取当前用户已收藏的留言ID
$favoritedIds = getFavoritedMessageIds();
$favoritedIds = array_flip($favoritedIds);

// 滚动数据（最新5条）
$scrollStmt = $db->query("SELECT id, type, title, created_at FROM messages WHERE status = 1 ORDER BY created_at DESC LIMIT 8");
$scrollMessages = $scrollStmt->fetchAll();

// 统计
$statsStmt = $db->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN type='help' THEN 1 ELSE 0 END) as help_count,
    SUM(CASE WHEN type='suggest' THEN 1 ELSE 0 END) as suggest_count,
    SUM(CASE WHEN type='lost' THEN 1 ELSE 0 END) as lost_count
    FROM messages WHERE status = 1");
$stats = $statsStmt->fetch();

include __DIR__ . '/includes/header.php';
?>

<!-- 滚动信息栏 -->
<div class="scroll-bar">
    <div class="container">
        <span class="scroll-label">📢 最新动态</span>
        <div class="scroll-wrapper">
            <div class="scroll-content" id="scrollContent">
                <?php foreach ($scrollMessages as $msg): ?>
                <a href="detail.php?id=<?= $msg['id'] ?>" class="scroll-item">
                    <span class="scroll-type"><?= getTypeIcon($msg['type']) ?></span>
                    <span class="scroll-title"><?= cleanInput($msg['title']) ?></span>
                    <span class="scroll-time"><?= timeAgo($msg['created_at']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- 统计卡片 -->
<section class="stats-section">
    <div class="container">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['total'] ?? 0 ?></div>
                <div class="stat-label">全部留言</div>
            </div>
            <div class="stat-card stat-help">
                <div class="stat-number"><?= $stats['help_count'] ?? 0 ?></div>
                <div class="stat-label">🆘 居民求助</div>
            </div>
            <div class="stat-card stat-suggest">
                <div class="stat-number"><?= $stats['suggest_count'] ?? 0 ?></div>
                <div class="stat-label">💡 意见建议</div>
            </div>
            <div class="stat-card stat-lost">
                <div class="stat-number"><?= $stats['lost_count'] ?? 0 ?></div>
                <div class="stat-label">🔍 失物招领</div>
            </div>
        </div>
    </div>
</section>

<!-- 筛选和排序 -->
<section class="filter-section">
    <div class="container">
        <div class="filter-bar">
            <div class="filter-types">
                <a href="index.php?<?= buildFilterQuery('', $range, $sort) ?>" class="filter-tag <?= !$type ? 'active' : '' ?>">全部</a>
                <a href="index.php?<?= buildFilterQuery('help', $range, $sort) ?>" class="filter-tag <?= $type === 'help' ? 'active' : '' ?>">🆘 求助</a>
                <a href="index.php?<?= buildFilterQuery('suggest', $range, $sort) ?>" class="filter-tag <?= $type === 'suggest' ? 'active' : '' ?>">💡 建议</a>
                <a href="index.php?<?= buildFilterQuery('lost', $range, $sort) ?>" class="filter-tag <?= $type === 'lost' ? 'active' : '' ?>">🔍 失物招领</a>
            </div>
            <div class="filter-sort">
                <a href="index.php?<?= buildFilterQuery($type, $range, 'time') ?>" class="sort-btn <?= $sort === 'time' ? 'active' : '' ?>">🕐 按时间</a>
                <a href="index.php?<?= buildFilterQuery($type, $range, 'hot') ?>" class="sort-btn <?= $sort === 'hot' ? 'active' : '' ?>">🔥 按热度</a>
            </div>
        </div>
        <div class="filter-bar filter-bar-sub">
            <div class="filter-ranges">
                <span class="filter-label">📅 时间范围</span>
                <a href="index.php?<?= buildFilterQuery($type, 'all', $sort) ?>" class="filter-tag <?= $range === 'all' ? 'active' : '' ?>">全部时间</a>
                <a href="index.php?<?= buildFilterQuery($type, 'today', $sort) ?>" class="filter-tag <?= $range === 'today' ? 'active' : '' ?>">今天</a>
                <a href="index.php?<?= buildFilterQuery($type, '7d', $sort) ?>" class="filter-tag <?= $range === '7d' ? 'active' : '' ?>">最近7天</a>
                <a href="index.php?<?= buildFilterQuery($type, '30d', $sort) ?>" class="filter-tag <?= $range === '30d' ? 'active' : '' ?>">最近30天</a>
            </div>
            <?php if ($hasActiveFilter): ?>
            <a href="index.php?reset=1" class="filter-reset">✕ 清除筛选</a>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- 当前结果摘要 -->
<section class="summary-section">
    <div class="container">
        <div class="result-summary">
            <div class="summary-counts">
                <span class="summary-total">共 <strong><?= $total ?></strong> 条结果</span>
                <span class="summary-item">🆘 求助 <?= (int)$summary['help_count'] ?></span>
                <span class="summary-item">💡 建议 <?= (int)$summary['suggest_count'] ?></span>
                <span class="summary-item">🔍 失物招领 <?= (int)$summary['lost_count'] ?></span>
            </div>
            <div class="summary-conditions">
                <?= $type ? getTypeLabel($type) : '全部类型' ?> · <?= getRangeLabel($range) ?> · <?= getSortLabel($sort) ?> · 第 <?= $page ?>/<?= $totalPages ?> 页
            </div>
        </div>
    </div>
</section>

<!-- 留言列表 -->
<section class="message-list-section">
    <div class="container">
        <?php if (empty($messages)): ?>
        <?php if ($hasActiveFilter): ?>
        <div class="empty-state">
            <div class="empty-icon">🔍</div>
            <p>没有找到符合条件的留言</p>
            <p class="empty-desc">当前条件：<?= $type ? getTypeLabel($type) : '全部类型' ?> · <?= getRangeLabel($range) ?></p>
            <div class="empty-actions">
                <a href="index.php?reset=1" class="btn btn-primary">恢复默认筛选</a>
                <a href="submit.php" class="btn btn-secondary">发布留言</a>
            </div>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon">📭</div>
            <p>暂无留言信息</p>
            <a href="submit.php" class="btn btn-primary">发布第一条留言</a>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="message-list">
            <?php foreach ($messages as $msg): ?>
            <div class="message-card">
                <a href="detail.php?id=<?= $msg['id'] ?>" class="card-link">
                    <div class="card-header">
                        <span class="card-type type-<?= $msg['type'] ?>"><?= getTypeIcon($msg['type']) ?> <?= getTypeLabel($msg['type']) ?></span>
                        <span class="card-time"><?= timeAgo($msg['created_at']) ?></span>
                    </div>
                    <h3 class="card-title"><?= cleanInput($msg['title']) ?></h3>
                    <p class="card-content"><?= cleanInput(mb_substr($msg['content'], 0, 80)) ?><?= mb_strlen($msg['content']) > 80 ? '...' : '' ?></p>
                    <div class="card-footer">
                        <span class="card-author">👤 <?= cleanInput($msg['nickname']) ?></span>
                        <?php if ($msg['image']): ?>
                        <span class="card-image">📷 有图</span>
                        <?php endif; ?>
                        <span class="card-views">👁 <?= $msg['views'] ?></span>
                    </div>
                </a>
                <button class="favorite-btn <?= isset($favoritedIds[$msg['id']]) ? 'favorited' : '' ?>" data-message-id="<?= $msg['id'] ?>" onclick="toggleFavorite(event, this)">
                    <span class="favorite-icon"><?= isset($favoritedIds[$msg['id']]) ? '⭐' : '☆' ?></span>
                    <span class="favorite-text"><?= isset($favoritedIds[$msg['id']]) ? '已收藏' : '收藏' ?></span>
                </button>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- 分页 -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="index.php?<?= buildFilterQuery($type, $range, $sort, $page - 1) ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="index.php?<?= buildFilterQuery($type, $range, $sort, $i) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="index.php?<?= buildFilterQuery($type, $range, $sort, $page + 1) ?>" class="page-btn">下一页</a>
            <?php endif; ?>
            <span class="page-info">第 <?= $page ?> / <?= $totalPages ?> 页 · 共 <?= $total ?> 条</span>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
