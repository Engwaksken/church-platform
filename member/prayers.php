<?php
$pageTitle = 'Prayer Guide';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/_helpers.php';
require_permission('member.worship.view');
$publishedPrayers = mp_table($conn, 'church_prayers')
    ? mp_rows($conn, "SELECT title,prayer_category,prayer_text,scripture_reference FROM church_prayers WHERE status='Published' ORDER BY prayer_category,title")
    : [];
include __DIR__ . '/../includes/sidebar.php';
?>
<div class="main-content"><?php include __DIR__ . '/../includes/topbar.php'; ?><div class="content-area">
<div class="page-header"><div><h2><i class="fas fa-praying-hands"></i> Prayer Guide</h2><p class="text-muted">A simple daily rhythm of prayer for members and families.</p></div></div>
<div class="member-prayer-intro"><strong>Pray regularly</strong><p>Set aside a quiet time each day. Begin with thanksgiving, read Scripture, bring your needs before God, and remember your family, church and community.</p></div>
<div class="member-prayer-grid">
<?php foreach ($publishedPrayers as $prayer): ?>
<article><span><?= mp_h(strtoupper(trim((string)($prayer['prayer_category'] ?? 'CHURCH PRAYER')))) ?></span><h3><?= mp_h($prayer['title']) ?></h3><p><?= nl2br(mp_h($prayer['prayer_text'])) ?></p><?php if (!empty($prayer['scripture_reference'])): ?><small class="member-prayer-reference"><?= mp_h($prayer['scripture_reference']) ?></small><?php endif; ?></article>
<?php endforeach; ?>
<article><span>01 · MORNING</span><h3>Begin the day with God</h3><p>Almighty God, thank you for the gift of this new day. Guide my thoughts, words and actions. Give me strength to serve others with love, humility and patience. Bless my family and keep us in your care. Through Jesus Christ our Lord. Amen.</p></article>
<article><span>02 · FOR THE CHURCH</span><h3>Pray for the Church of Uganda</h3><p>Heavenly Father, bless your Church in Uganda. Guide our bishops, clergy, lay readers and all who serve. Strengthen our congregations in faith, unity and witness. Help us care for the poor, seek justice and share the hope of Christ. Amen.</p></article>
<article><span>03 · FOR OTHERS</span><h3>Pray for our community</h3><p>Lord Jesus, remember those who are sick, grieving, lonely or in need. Give comfort to the sorrowful, healing to the sick, wisdom to our leaders and peace to our communities. Show us how we may be instruments of your compassion. Amen.</p></article>
<article><span>04 · EVENING</span><h3>End the day in peace</h3><p>Gracious God, thank you for carrying me through this day. Forgive what I have done wrong, help me make amends where needed, and give me rest tonight. I place those I love and all my concerns in your hands. Amen.</p></article>
<article class="member-prayer-scripture"><span>DAILY SCRIPTURE</span><h3>“Pray continually.”</h3><p>1 Thessalonians 5:17</p></article>
</div></div></div>
<style>.member-prayer-intro{padding:20px 24px;margin-bottom:18px;background:#f4effb;border-left:4px solid #4a1e8f;border-radius:10px}.member-prayer-intro p{margin:6px 0 0}.member-prayer-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}.member-prayer-grid article{padding:20px;background:#fff;border:1px solid #e4e1e9;border-radius:12px;line-height:1.65}.member-prayer-grid article span{font-size:11px;font-weight:800;color:#67449b;letter-spacing:.06em}.member-prayer-grid h3{font-size:18px;margin:9px 0}.member-prayer-grid p{margin:0}.member-prayer-reference{display:block;margin-top:10px;font-weight:700;color:#64428e}.member-prayer-scripture{background:#f8f6fb!important}</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
