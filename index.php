<?php
require_once __DIR__.'/includes/bootstrap.php';
$specs=$pdo->query('SELECT id,name FROM specializations ORDER BY name')->fetchAll();
$locations=$pdo->query('SELECT id,city_name FROM locations ORDER BY city_name')->fetchAll();
$featured=$pdo->query("SELECT lp.id,lp.full_name,lp.experience_years,lp.bio,lp.rating,lp.avatar_file,l.city_name,
GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') specialties
FROM lawyer_profiles lp JOIN users u ON u.id=lp.user_id AND u.is_active=1
LEFT JOIN locations l ON l.id=lp.location_id LEFT JOIN lawyer_specialties ls ON ls.lawyer_id=lp.id LEFT JOIN specializations s ON s.id=ls.specialization_id
WHERE lp.is_verified=1 GROUP BY lp.id ORDER BY lp.rating DESC,lp.experience_years DESC,lp.full_name LIMIT 15")->fetchAll();
$featuredPages=array_chunk($featured, 3);
$news=$pdo->query("SELECT id,title,body,category,image_file,link_url,updated_at FROM content_management WHERE type='News' AND is_published=1 ORDER BY sort_order,updated_at DESC,id DESC LIMIT 15")->fetchAll();
$newsPages=array_chunk($news, 3);
page_header('Home');
?>
<section class="hero" style="display:block;text-align:center">
<div>
<p class="eyebrow" style="color:#b9cdf5">Legal help, made easier</p>
<h1>Find the right lawyer. Book with confidence.</h1>
<p style="margin-inline:auto">Search verified lawyers by legal issue, lawyer name, specialization and city/province, then review profiles and reserve a one-hour consultation.</p>
</div>
<form class="home-search-panel" id="homeLawyerSearchForm" action="/LegalEase_eProject/public/lawyers.php" method="get">
<div class="home-search-row">
<input class="home-keyword" id="homeLawyerSearch" name="q" aria-label="Search legal issue or lawyer name" placeholder="Search legal issue or lawyer name" autocomplete="off">
<select id="homeLawyerSpec" name="specialization" aria-label="Specialization">
<option value="0">All specializations</option><?php
foreach($specs as $s):?><option value="<?= (int)$s['id']?>"><?= e($s['name'])?></option><?php
endforeach?></select>
<select id="homeLawyerLoc" name="location" aria-label="City or Province">
<option value="0">All cities / provinces</option><?php
foreach($locations as $l):?><option value="<?= (int)$l['id']?>"><?= e($l['city_name'])?></option><?php
endforeach?></select>
<button class="btn home-search-btn" type="submit">Search Lawyers</button>
</div>
<div class="search-hints">
<span>Popular:</span>
<a href="/LegalEase_eProject/public/lawyers.php?specialization=7">Insurance</a>
<a href="/LegalEase_eProject/public/lawyers.php?specialization=8">Intellectual Property</a>
<a href="/LegalEase_eProject/public/lawyers.php?specialization=9">Labor &amp; Employment</a>
</div>
<div id="homeSearchStatus" class="home-live-status" aria-live="polite">
</div>
</form>
<div id="homeLiveResultsWrap" class="home-live-results" hidden>
<div class="home-live-results-head">
<strong>Live search results</strong>
<a href="/LegalEase_eProject/public/lawyers.php" id="homeSeeAll">See all results</a>
</div>
<div id="homeLiveResults" class="directory-list">
</div>
<div id="homeLivePagination">
</div>
</div>
</section>
<section class="section featured-section">
<div class="section-heading">
<div>
<p class="eyebrow">Trusted professionals</p>
<h2>Featured verified lawyers</h2>
</div>
<a class="feature-more" href="/LegalEase_eProject/public/lawyers.php">View More</a>
</div>
<div class="featured-carousel-wrap">
<button type="button" class="carousel-btn carousel-left" id="featuredPrev" aria-label="Previous lawyers">‹</button>
<div class="lawyer-carousel-viewport">
<div class="lawyer-carousel-track" id="featuredTrack">
   <?php
foreach($featuredPages as $pageLawyers):?><div class="featured-page"><?php
foreach($pageLawyers as $l):?><article class="featured-lawyer-card">
<div class="lawyer-card-top">
<img class="featured-avatar" src="/LegalEase_eProject/<?=e($l['avatar_file'])?>" alt="<?=e($l['full_name'])?>">
<div class="featured-intro">
<span class="verified">✓ Verified</span>
<h3><?=e($l['full_name'])?></h3>
<div class="star-row">★★★★★ <span><?=number_format((float)$l['rating'], 1)?></span>
</div>
<div class="muted location-line">⌖ <?=e($l['city_name'])?></div>
</div>
</div>
<div class="practice-label">SPECIALIZATIONS</div>
<p class="practice-list"><?=e($l['specialties'])?></p>
<div class="card-divider">
</div>
<h4>Lawyer information</h4>
<p class="lawyer-summary"><?=e(mb_strimwidth($l['bio']??'', 0, 175, '...'))?></p>
<div class="featured-card-actions single">
<a class="btn profile-btn" href="/LegalEase_eProject/public/lawyer.php?id=<?=(int)$l['id']?>">View &amp; Book</a>
</div>
</article><?php
endforeach?></div><?php
endforeach?>
   </div>
</div>
<button type="button" class="carousel-btn carousel-right" id="featuredNext" aria-label="Next lawyers">›</button>
</div>
<div class="carousel-controls" style="justify-content:center;margin-top:8px">
<span id="featuredCounter">1 / <?=max(1, count($featuredPages))?></span>
</div>
</section>
<section class="section" id="news">
<style>
  #news .section-heading{margin-bottom:22px}
  #news .news-carousel{display:grid;grid-template-columns:44px minmax(0,1fr) 44px;align-items:center;gap:14px}
  #news .news-viewport{overflow:hidden}
  #news .news-track{display:flex;transition:transform .42s ease}
  #news .news-slide{flex:0 0 100%;min-width:100%;display:flex;gap:26px;justify-content:center;box-sizing:border-box;padding:4px 2px}
  #news .n-card{flex:0 0 calc((100% - 52px)/3);max-width:calc((100% - 52px)/3);display:flex;flex-direction:column;background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(16,35,65,.08)}
  #news .n-img{width:100%;height:190px;object-fit:cover;flex:none;display:block;background:#e8edf5}
  #news .n-body{padding:18px 20px 20px;display:flex;flex-direction:column;flex:1}
  #news .n-tag{align-self:flex-start;background:#eef4ff;color:#234b93;font-size:11px;font-weight:800;letter-spacing:.07em;text-transform:uppercase;padding:4px 10px;border-radius:999px;margin-bottom:10px}
  #news .n-body h3{margin:0 0 9px;font-size:17px;line-height:1.35;color:#173b7b;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:46px}
  #news .n-body p{margin:0 0 16px;color:#536178;font-size:13.5px;line-height:1.55;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;flex:1}
  #news .n-foot{display:flex;justify-content:space-between;align-items:center;gap:12px;font-size:12.5px;border-top:1px solid var(--line);padding-top:12px}
  #news .n-foot .muted{white-space:nowrap}
  #news .news-counter{display:flex;justify-content:center;margin-top:16px;color:#64748b;font-weight:700;font-size:14px}
  #news .carousel-btn:disabled{opacity:.3;cursor:not-allowed}
  @media(max-width:900px){#news .news-carousel{grid-template-columns:1fr}#news .carousel-btn{display:none}#news .news-slide{gap:18px;flex-wrap:wrap}#news .n-card{flex-basis:calc((100% - 18px)/2);max-width:calc((100% - 18px)/2)}}
  @media(max-width:600px){#news .n-card{flex-basis:100%;max-width:100%}}
 </style>
<div class="section-heading">
<div>
<p class="eyebrow">Stay informed</p>
<h2>Legal News</h2>
</div>
<a class="feature-more" href="/LegalEase_eProject/public/news.php">View More</a>
</div>
<div class="news-carousel">
<button type="button" class="carousel-btn" id="newsPrev" aria-label="Previous news">‹</button>
<div class="news-viewport">
<div class="news-track" id="newsTrack">
   <?php
foreach($newsPages as $pageNews):?><div class="news-slide"><?php
foreach($pageNews as $n):?><article class="n-card">
      <?php
if($n['image_file']):?><img class="n-img" src="/LegalEase_eProject/<?=e($n['image_file'])?>" alt="<?=e($n['title'])?>"><?php
endif?>
      <div class="n-body">
        <?php
if($n['category']):?><span class="n-tag"><?=e($n['category'])?></span><?php
endif?>
        <h3><?=e($n['title'])?></h3>
<p><?=e(mb_strimwidth(strip_tags($n['body']), 0, 180, '...'))?></p>
<div class="n-foot">
<span class="muted"><?=e(date('d M Y', strtotime($n['updated_at'])))?></span><?php
if($n['link_url']):?><a class="feature-more" href="<?=e($n['link_url'])?>" target="_blank" rel="noopener">Read more &rsaquo;</a><?php
endif?></div>
</div>
</article><?php
endforeach?></div><?php
endforeach?>
   </div>
</div>
<button type="button" class="carousel-btn" id="newsNext" aria-label="Next news">›</button>
</div>
<div class="news-counter">
<span id="newsCounter">1 / <?=max(1, count($newsPages))?></span>
</div>
</section>
<script>
(function(){
  function initCarousel(trackId,prevId,nextId,counterId){
    const track=document.getElementById(trackId);if(!track)return;
    const pages=[...track.children],prev=document.getElementById(prevId),next=document.getElementById(nextId),counter=document.getElementById(counterId);
    if(!pages.length)return;let page=0;const total=pages.length;
    function render(){track.style.transform=`translateX(-${page*100}%)`;if(counter)counter.textContent=`${page+1} / ${total}`;}
    if(prev)prev.onclick=()=>{page=(page-1+total)%total;render();};
    if(next)next.onclick=()=>{page=(page+1)%total;render();};
    render();
  }
  initCarousel('featuredTrack','featuredPrev','featuredNext','featuredCounter');
  initCarousel('newsTrack','newsPrev','newsNext','newsCounter');
})();
</script>
<script>
(()=>{
 const form=document.getElementById('homeLawyerSearchForm'),q=document.getElementById('homeLawyerSearch'),spec=document.getElementById('homeLawyerSpec'),loc=document.getElementById('homeLawyerLoc'),wrap=document.getElementById('homeLiveResultsWrap'),results=document.getElementById('homeLiveResults'),pager=document.getElementById('homeLivePagination'),status=document.getElementById('homeSearchStatus'),seeAll=document.getElementById('homeSeeAll');
 if(!form)return; let timer=null,controller=null,page=1;
 function active(){return q.value.trim()!=='' || spec.value!=='0' || loc.value!=='0';}
 function updateSeeAll(){const p=new URLSearchParams();if(q.value.trim())p.set('q',q.value.trim());if(spec.value!=='0')p.set('specialization',spec.value);if(loc.value!=='0')p.set('location',loc.value);seeAll.href='/LegalEase_eProject/public/lawyers.php'+(p.toString()?'?'+p.toString():'');}
 async function load(reset=true){if(reset)page=1;updateSeeAll();if(!active()){wrap.hidden=true;status.textContent='';return;}controller?.abort();controller=new AbortController();status.textContent='Searching verified lawyers…';const p=new URLSearchParams({q:q.value.trim(),specialization:spec.value,location:loc.value,sort:q.value.trim()?'name':'rating',page:String(page)});try{const r=await fetch('/LegalEase_eProject/public/lawyer_search_ajax.php?'+p.toString(),{signal:controller.signal,headers:{'X-Requested-With':'XMLHttpRequest'}});if(!r.ok)throw new Error('HTTP '+r.status);const d=await r.json();results.innerHTML=d.html;pager.innerHTML=d.pagination;wrap.hidden=false;status.textContent=d.total+' matching lawyer'+(d.total===1?'':'s');pager.querySelectorAll('[data-page]').forEach(b=>b.addEventListener('click',()=>{page=Number(b.dataset.page);load(false);wrap.scrollIntoView({behavior:'smooth',block:'start'});}));}catch(e){if(e.name!=='AbortError')status.textContent='Unable to load live results. Please try again.';}}
 function debounce(){clearTimeout(timer);timer=setTimeout(()=>load(true),220)}
 q.addEventListener('input',debounce);spec.addEventListener('change',()=>load(true));loc.addEventListener('change',()=>load(true));form.addEventListener('submit',e=>{e.preventDefault();load(true)});
})();
</script>
<?php
page_footer();
?>
