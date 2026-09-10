<?php
require_once __DIR__.'/../includes/bootstrap.php';
$specs=$pdo->query('SELECT * FROM specializations ORDER BY name')->fetchAll();
$locations=$pdo->query('SELECT * FROM locations ORDER BY city_name')->fetchAll();
page_header('Find Lawyers');
?>
<section class="section">
<div class="section-heading">
<div>
<p class="eyebrow">Verified lawyer directory</p>
<h1>Find the right lawyer</h1>
<p class="muted">Search results update instantly as you type.</p>
</div>
</div>
<form class="directory-search" id="lawyerSearchForm">
<div class="directory-search-grid">
<input name="q" id="lawyerLiveSearch" placeholder="Search legal issue or lawyer name" autocomplete="off">
<select name="specialization" id="lawyerSpec">
<option value="0">All specializations</option><?php
foreach($specs as $s):?><option value="<?=$s['id']?>"><?=e($s['name'])?></option><?php
endforeach?></select>
<select name="location" id="lawyerLoc">
<option value="0">All cities/provinces</option><?php
foreach($locations as $l):?><option value="<?=$l['id']?>"><?=e($l['city_name'])?></option><?php
endforeach?></select>
<button type="submit">Search</button>
</div>
<div class="live-search-meta">
<label>Sort by <select name="sort" id="lawyerSort">
<option value="rating">Highest rating</option>
<option value="experience">Most experience</option>
<option value="name">Name A–Z</option>
</select>
</label>
<span id="resultCount" class="muted">
</span>
<span id="liveStatus" class="muted" aria-live="polite">
</span>
</div>
</form>
<div id="lawyerResults" class="directory-list">
<div class="empty">Loading lawyers…</div>
</div>
<div id="lawyerPagination">
</div>
</section>
<script>
(()=>{const form=document.getElementById('lawyerSearchForm'),q=document.getElementById('lawyerLiveSearch'),spec=document.getElementById('lawyerSpec'),loc=document.getElementById('lawyerLoc'),sort=document.getElementById('lawyerSort'),results=document.getElementById('lawyerResults'),pager=document.getElementById('lawyerPagination'),count=document.getElementById('resultCount'),status=document.getElementById('liveStatus');let timer=null,controller=null,page=1,typed=false;async function load(resetPage=true){if(resetPage)page=1;if(q.value.trim()&&!typed){sort.value='name';typed=true}else if(!q.value.trim())typed=false;controller?.abort();controller=new AbortController();status.textContent='Searching…';const p=new URLSearchParams({q:q.value,specialization:spec.value,location:loc.value,sort:sort.value,page});try{const r=await fetch('/LegalEase_eProject/public/lawyer_search_ajax.php?'+p,{signal:controller.signal});const d=await r.json();results.innerHTML=d.html;pager.innerHTML=d.pagination;count.textContent=d.total+' lawyer'+(d.total===1?'':'s');status.textContent='';pager.querySelectorAll('[data-page]').forEach(b=>b.onclick=()=>{page=Number(b.dataset.page);load(false);window.scrollTo({top:form.offsetTop-80,behavior:'smooth'})});}catch(e){if(e.name!=='AbortError'){status.textContent='Unable to load results.'}}}function debounce(){clearTimeout(timer);timer=setTimeout(()=>load(true),220)}q.addEventListener('input',debounce);spec.addEventListener('change',()=>load(true));loc.addEventListener('change',()=>load(true));sort.addEventListener('change',()=>load(true));form.addEventListener('submit',e=>{e.preventDefault();load(true)});load(true)})();
</script>
<?php
page_footer();
?>
