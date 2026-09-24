<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/config/database.php';

$pageTitle = '社区便民留言板 - 首页';
$currentPage = 'home';
$cssPath = 'assets/css/style.css';
$jsPath = 'assets/js/main.js';

$db = getDB();

// 组合筛选条件：类型 + 时间范围 + 排序 + 视图（记忆上次选择，兼容旧链接）
$filters = resolveHomeFilters();

// 列表、摘要、分页共用同一套查询条件，保证口径一致
list($where, $params, $orderBy) = buildHomeMessageQuery($filters);

$pageSize = 10;
$page = max(1, intval($_GET['page'] ?? 1));

// 总数
$countStmt = $db->prepare("SELECT COUNT(*) FROM messages $where");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($total / $pageSize);

// 超出范围的页码（如切换条件后）重定向到最后一页，避免出现“对不上”的空列表
if ($total > 0 && $page > $totalPages) {
    header('Location: ' . homeFilterUrl($filters, ['page' => $totalPages]));
    exit;
}
if ($total === 0) {
    $page = 1;
}

$offset = ($page - 1) * $pageSize;

// 当前页数据（列表与摘要共用同一份数据）
$sql = "SELECT id, nickname, type, title, content, image, views, created_at
        FROM messages $where ORDER BY $orderBy LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

// 摘要视图概览：当前条件下各类型数量、总浏览量，与总数同一 WHERE 口径
$typeStats = ['help' => 0, 'suggest' => 0, 'lost' => 0];
$summaryViews = 0;
if (($filters['view'] ?? '') === 'summary' && $total > 0) {
    $summaryStmt = $db->prepare("SELECT type, COUNT(*) AS cnt, COALESCE(SUM(views), 0) AS views_sum
        FROM messages $where GROUP BY type");
    $summaryStmt->execute($params);
    foreach ($summaryStmt->fetchAll() as $row) {
        if (isset($typeStats[$row['type']])) {
            $typeStats[$row['type']] = (int)$row['cnt'];
        }
        $summaryViews += (int)$row['views_sum'];
    }

    // 热门 TOP3：当前条件下浏览量最高的留言
    $topStmt = $db->prepare("SELECT id, type, title, views, created_at
        FROM messages $where ORDER BY views DESC, created_at DESC LIMIT 3");
    $topStmt->execute($params);
    $topMessages = $topStmt->fetchAll();
} else {
    $topMessages = [];
}

// 获取当前用户已收藏的留言ID
$favoritedIds = array_flip(getFavoritedMessageIds());

// 滚动数据（最新8条，不受筛选条件影响）
$scrollStmt = $db->query("SELECT id, type, title, created_at FROM messages WHERE status = 1 ORDER BY created_at DESC LIMIT 8");
$scrollMessages = $scrollStmt->fetchAll();

// 全站统计（不受筛选条件影响）
$statsStmt = $db->query("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN type='help' THEN 1 ELSE 0 END) as help_count,
    SUM(CASE WHEN type='suggest' THEN 1 ELSE 0 END) as suggest_count,
    SUM(CASE WHEN type='lost' THEN 1 ELSE 0 END) as lost_count
    FROM messages WHERE status = 1");
$stats = $statsStmt->fetch();

$rangeLabels = homeRangeOptions();
$sortLabels = homeSortOptions();
$isDefaultFilters = isDefaultHomeFilters($filters);

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

<!-- 组合筛选：类型 + 时间范围 + 排序 + 视图切换 -->
<section class="filter-section">
    <div class="container">
        <div class="filter-bar">
            <div class="filter-types">
                <?php foreach (homeTypeOptions() as $typeKey => $typeLabel): ?>
                <a href="<?= homeFilterUrl($filters, ['type' => $typeKey, 'page' => 1], true) ?>"
                   class="filter-tag <?= $filters['type'] === $typeKey ? 'active' : '' ?>">
                    <?= $typeLabel ?>
                </a>
                <?php endforeach; ?>
            </div>
            <div class="filter-controls">
                <div class="filter-group">
                    <span class="filter-group-label">时间</span>
                    <div class="filter-ranges">
                        <?php foreach ($rangeLabels as $rangeKey => $rangeLabel): ?>
                        <a href="<?= homeFilterUrl($filters, ['range' => (string)$rangeKey, 'page' => 1], true) ?>"
                           class="filter-chip <?= $filters['range'] === (string)$rangeKey ? 'active' : '' ?>"><?= $rangeLabel ?></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="filter-sort">
                    <?php foreach ($sortLabels as $sortKey => $sortLabel): ?>
                    <a href="<?= homeFilterUrl($filters, ['sort' => $sortKey, 'page' => 1], true) ?>"
                       class="sort-btn <?= $filters['sort'] === $sortKey ? 'active' : '' ?>"><?= $sortLabel ?></a>
                    <?php endforeach; ?>
                </div>
                <div class="filter-view">
                    <?php foreach (homeViewOptions() as $viewKey => $viewLabel): ?>
                    <a href="<?= homeFilterUrl($filters, ['view' => $viewKey], true) ?>"
                       class="view-btn <?= $filters['view'] === $viewKey ? 'active' : '' ?>"><?= $viewLabel ?></a>
                    <?php endforeach; ?>
                </div>
                <?php if (!$isDefaultFilters): ?>
                <a href="index.php?reset=1" class="filter-reset" title="清除筛选条件，恢复默认">↺ 恢复默认</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- 留言列表 / 摘要 -->
<section class="message-list-section">
    <div class="container">
        <?php if (empty($messages)): ?>
        <div class="empty-state">
            <div class="empty-icon">🔍</div>
            <?php if ($isDefaultFilters): ?>
            <p>暂无留言信息</p>
            <a href="submit.php" class="btn btn-primary">发布第一条留言</a>
            <?php else: ?>
            <p>当前组合条件下没有找到留言</p>
            <p class="empty-filters-desc">
                类型：<?= homeTypeOptions()[$filters['type']] ?> ·
                时间：<?= $rangeLabels[$filters['range']] ?> ·
                排序：<?= $sortLabels[$filters['sort']] ?>
            </p>
            <div class="empty-actions">
                <a href="index.php?reset=1" class="btn btn-primary">↺ 恢复默认条件</a>
                <a href="submit.php" class="btn btn-secondary">发布留言</a>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>

        <!-- 当前结果概览（条件描述 + 结果数，列表与摘要均展示） -->
        <div class="result-summary-bar">
            <span class="result-summary-text">
                当前条件：
                <strong><?= homeTypeOptions()[$filters['type']] ?></strong> ·
                <strong><?= $rangeLabels[$filters['range']] ?></strong> ·
                <strong><?= $sortLabels[$filters['sort']] ?></strong>
            </span>
            <span class="result-summary-count">共 <strong><?= $total ?></strong> 条结果，第 <?= $page ?> / <?= max(1, $totalPages) ?> 页</span>
        </div>

        <?php if ($filters['view'] === 'summary'): ?>
        <!-- 摘要视图：概览当前结果 -->
        <div class="summary-panel">
            <div class="summary-overview">
                <h3 class="summary-title">📊 结果概览</h3>
                <div class="summary-stat-grid">
                    <div class="summary-stat">
                        <div class="summary-stat-number"><?= $total ?></div>
                        <div class="summary-stat-label">匹配留言</div>
                    </div>
                    <div class="summary-stat stat-help">
                        <div class="summary-stat-number"><?= $typeStats['help'] ?></div>
                        <div class="summary-stat-label">🆘 居民求助</div>
                    </div>
                    <div class="summary-stat stat-suggest">
                        <div class="summary-stat-number"><?= $typeStats['suggest'] ?></div>
                        <div class="summary-stat-label">💡 意见建议</div>
                    </div>
                    <div class="summary-stat stat-lost">
                        <div class="summary-stat-number"><?= $typeStats['lost'] ?></div>
                        <div class="summary-stat-label">🔍 失物招领</div>
                    </div>
                    <div class="summary-stat">
                        <div class="summary-stat-number"><?= $summaryViews ?></div>
                        <div class="summary-stat-label">👁 累计浏览</div>
                    </div>
                </div>
            </div>
            <?php if (!empty($topMessages)): ?>
            <div class="summary-top">
                <h4 class="summary-subtitle">🔥 热门 TOP3</h4>
                <ol class="summary-top-list">
                    <?php foreach ($topMessages as $index => $top): ?>
                    <li>
                        <span class="summary-top-rank rank-<?= $index + 1 ?>"><?= $index + 1 ?></span>
                        <span class="card-type type-<?= $top['type'] ?>"><?= getTypeIcon($top['type']) ?> <?= getTypeLabel($top['type']) ?></span>
                        <a href="detail.php?id=<?= $top['id'] ?>" class="summary-top-title"><?= cleanInput($top['title']) ?></a>
                        <span class="summary-top-views">👁 <?= $top['views'] ?></span>
                    </li>
                    <?php endforeach; ?>
                </ol>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="message-list <?= $filters['view'] === 'summary' ? 'message-list-compact' : '' ?>">
            <?php foreach ($messages as $msg): ?>
            <div class="message-card">
                <a href="detail.php?id=<?= $msg['id'] ?>" class="card-link">
                    <div class="card-header">
                        <span class="card-type type-<?= $msg['type'] ?>"><?= getTypeIcon($msg['type']) ?> <?= getTypeLabel($msg['type']) ?></span>
                        <span class="card-time"><?= timeAgo($msg['created_at']) ?></span>
                    </div>
                    <h3 class="card-title"><?= cleanInput($msg['title']) ?></h3>
                    <?php if ($filters['view'] !== 'summary'): ?>
                    <p class="card-content"><?= cleanInput(mb_substr($msg['content'], 0, 80)) ?><?= mb_strlen($msg['content']) > 80 ? '...' : '' ?></p>
                    <?php endif; ?>
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

        <!-- 分页（与列表/摘要共用同一总数与当前页） -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="<?= homeFilterUrl($filters, ['page' => $page - 1]) ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="<?= homeFilterUrl($filters, ['page' => $i]) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="<?= homeFilterUrl($filters, ['page' => $page + 1]) ?>" class="page-btn">下一页</a>
            <?php endif; ?>
            <span class="page-info">第 <?= $page ?>/<?= $totalPages ?> 页</span>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>
