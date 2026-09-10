<?php
require_once __DIR__.'/../includes/bootstrap.php';
$page=max(1, (int)($_GET['page']??1));
$per=10;
$total=(int)$pdo->query("SELECT COUNT(*) FROM content_management WHERE type='News' AND is_published=1")->fetchColumn();
$pages=max(1, (int)ceil($total/$per));
if($page>$pages)$page=$pages;
$offset=($page-1)*$per;
$rows=$pdo->query("SELECT title,body,category,image_file,link_url,updated_at FROM content_management WHERE type='News' AND is_published=1 ORDER BY sort_order,updated_at DESC,id DESC LIMIT $per OFFSET $offset")->fetchAll();
page_header('Legal News');
?>
<section class="section" id="newspage">
<style>
  #newspage .section-heading{margin-bottom:26px}
  #newspage .news-list-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:26px;align-items:stretch}
  #newspage .n-card{display:flex;flex-direction:column;background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(16,35,65,.08);transition:transform .18s ease,box-shadow .18s ease}
  #newspage .n-card:hover{transform:translateY(-3px);box-shadow:0 16px 38px rgba(16,35,65,.13)}
  #newspage .n-img{width:100%;height:190px;object-fit:cover;flex:none;display:block;background:#e8edf5}
  #newspage .n-body{padding:18px 20px 20px;display:flex;flex-direction:column;flex:1}
  #newspage .n-tag{align-self:flex-start;background:#eef4ff;color:#234b93;font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;padding:4px 10px;border-radius:999px;margin-bottom:10px}
  #newspage .n-body h3{margin:0 0 9px;font-size:17px;line-height:1.35;color:#173b7b;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:46px}
  #newspage .n-body p{margin:0 0 16px;color:#536178;font-size:13.5px;line-height:1.55;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;flex:1}
  #newspage .n-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:12.5px;border-top:1px solid var(--line);padding-top:12px}
  #newspage .n-foot .muted{white-space:nowrap}
  @media(max-width:980px){#newspage .news-list-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media(max-width:640px){#newspage .news-list-grid{grid-template-columns:1fr}}
 </style>
<div class="section-heading">
<div>
<p class="eyebrow">Stay informed</p>
<h1>Legal News</h1>
<p class="muted">Recent changes to Vietnamese law that may affect individuals and businesses.</p>
</div>
</div>
 <?php
if(!$rows):?><p class="muted">No news published yet.</p><?php
endif?>
 <div class="news-list-grid">
  <?php
foreach($rows as $n):?>
  <article class="n-card">
   <?php
if($n['image_file']):?><img class="n-img" src="/LegalEase_eProject/<?=e($n['image_file'])?>" alt="<?=e($n['title'])?>"><?php
endif?>
   <div class="n-body">
    <?php
if($n['category']):?><span class="n-tag"><?=e($n['category'])?></span><?php
endif?>
    <h3><?=e($n['title'])?></h3>
<p><?=e(mb_strimwidth(strip_tags($n['body']), 0, 200, '...'))?></p>
<div class="n-foot">
<span class="muted"><?=e(date('d M Y', strtotime($n['updated_at'])))?></span>
     <?php
if($n['link_url']):?><a class="feature-more" href="<?=e($n['link_url'])?>" target="_blank" rel="noopener">Read more &rsaquo;</a><?php
endif?>
    </div>
</div>
</article>
  <?php
endforeach?>
 </div><?=paginate($total, $page, $per, '/LegalEase_eProject/public/news.php')?>
</section>
<?php
page_footer();
?>
