<?php
require_once __DIR__.'/includes/auth.php';
$active_page='content';
if(is_post()) {
    verify_csrf();
    $action=$_POST['action']??'create';
    $cat=trim($_POST['category']??'');
    $img=trim($_POST['image_file']??'');
    $link=trim($_POST['link_url']??'');
    $sort=(int)($_POST['sort_order']??0);
    if($action==='create') {
        $type=$_POST['type']??'FAQ';
        if(!in_array($type, ['FAQ', 'Guide', 'News', 'Announcement'], true))$type='FAQ';
        $pdo->prepare("INSERT INTO content_management(type,category,title,body,image_file,link_url,sort_order,created_by,is_published) VALUES(?,?,?,?,?,?,?,?,1)")
        ->execute([$type, $cat?:null, trim($_POST['title']??''), trim($_POST['body']??''), $img?:null, $link?:null, $sort, user()['id']]);
        flash('success', 'Content published.');
    }elseif($action==='edit') {
        $pdo->prepare("UPDATE content_management SET category=?,title=?,body=?,image_file=?,link_url=?,sort_order=?,is_published=?,updated_at=NOW() WHERE id=?")
        ->execute([$cat?:null, trim($_POST['title']??''), trim($_POST['body']??''), $img?:null, $link?:null, $sort, isset($_POST['is_published'])?1:0, (int)$_POST['id']]);
        flash('success', 'Content updated.');
    }elseif($action==='delete') {
        $pdo->prepare("DELETE FROM content_management WHERE id=?")->execute([(int)$_POST['id']]);
        flash('success', 'Content deleted.');
    }
    redirect('/LegalEase_eProject/admin/content.php'.(!empty($_GET['type'])?'?type='.urlencode($_GET['type']):''));
}
$type=$_GET['type']??'';
$where='';
$params=[];
if(in_array($type, ['FAQ', 'Guide', 'News', 'Announcement'], true)) {
    $where='WHERE type=?';
    $params[]=$type;
}
$q=$pdo->prepare("SELECT * FROM content_management $where ORDER BY type,sort_order,updated_at DESC,id DESC");
$q->execute($params);
$rows=$q->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Content Management - LegalEase</title>
<link rel="stylesheet" href="../assets/css/admin_base.css">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>
<div class="lawyer-layout"><?php
require __DIR__.'/includes/sidebar.php';
?><main class="main-content">
<h1>Content Management</h1>
<p>Update the homepage FAQs, Guides / Notices and the Legal News section. Changes here appear immediately on
<a href="../public/faq.php" target="_blank">the FAQ &amp; Guides page</a> and <a href="../public/news.php" target="_blank">the Legal News page</a>.</p>
<br>
<?php
render_flash();
?>
<div class="card" style="margin-bottom:25px">
<h2>Add New Content</h2>
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create">
<label>Type</label>
<select name="type" id="newType">
<option value="FAQ">FAQ</option>
<option value="Guide">Guide / Notice</option>
<option value="News">News</option>
<option value="Announcement">Announcement</option>
</select>
<label>Category / Group <span style="font-weight:400">(e.g. "Booking &amp; Consultation" for FAQ, "Tax" for News)</span>
</label>
<input name="category" placeholder="Category">
<label>Title / Question</label>
<input name="title" placeholder="Content title" required>
<label>Body / Answer</label>
<textarea name="body" rows="4" placeholder="Content body" required>
</textarea>
<label>Image path <span style="font-weight:400">(News only, e.g. assets/images/news/news1.svg)</span>
</label>
<input name="image_file" placeholder="assets/images/news/....svg">
<label>External link <span style="font-weight:400">(News only - "Read more" URL)</span>
</label>
<input name="link_url" placeholder="https://...">
<label>Sort order <span style="font-weight:400">(lower shows first)</span>
</label>
<input type="number" name="sort_order" value="0">
<button>PUBLISH</button>
</form>
</div>
<div class="tabs">
<a href="content.php" class="<?=$type===''?'active':''?>">All</a>
<a href="content.php?type=FAQ" class="<?=$type==='FAQ'?'active':''?>">FAQ</a>
<a href="content.php?type=Guide" class="<?=$type==='Guide'?'active':''?>">Guides / Notices</a>
<a href="content.php?type=News" class="<?=$type==='News'?'active':''?>">News</a>
<a href="content.php?type=Announcement" class="<?=$type==='Announcement'?'active':''?>">Announcements</a>
</div>
<?php
if(!$rows):?><div class="card">
<p>No content in this category yet.</p>
</div><?php
endif?>
<?php
foreach($rows as $r):?>
<div class="card" style="margin-bottom:20px">
<div class="page-header">
<div>
<h2><?=e($r['title'])?></h2>
<p><?=e(strtoupper($r['type']))?><?=$r['category']?' &middot; '.e($r['category']):''?> &middot; order <?=(int)$r['sort_order']?> &middot; updated <?=e(substr($r['updated_at'], 0, 10))?><?=$r['is_published']?'':' &middot; HIDDEN'?></p>
</div>
<span class="badge badge-active"><?=e(strtoupper($r['type']))?></span>
</div>
 <?php
if($r['image_file']):?><img src="../<?=e($r['image_file'])?>" alt="" style="max-width:260px;border-radius:8px;margin-bottom:10px"><?php
endif?>
 <p><?=nl2br(e($r['body']))?></p>
 <?php
if($r['link_url']):?><p>
<a href="<?=e($r['link_url'])?>" target="_blank"><?=e($r['link_url'])?></a>
</p><?php
endif?>
 <br>
<div class="action-links">
<button class="btn-small btn-neutral" type="button" onclick="toggleEdit(<?=$r['id']?>)">Edit</button>
<form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$r['id']?>">
<button name="action" value="delete" class="btn-small btn-danger" onclick="return confirm('Delete this content?')">Delete</button>
</form>
</div>
<form method="post" id="edit-form-<?=$r['id']?>" class="edit-panel" style="display:none"><?=csrf_field()?>
  <input type="hidden" name="id" value="<?=$r['id']?>">
<label>Category / Group</label>
<input name="category" value="<?=e($r['category'])?>">
<label>Title / Question</label>
<input name="title" value="<?=e($r['title'])?>">
<label>Body / Answer</label>
<textarea name="body" rows="4"><?=e($r['body'])?></textarea>
<label>Image path (News)</label>
<input name="image_file" value="<?=e($r['image_file'])?>">
<label>External link (News)</label>
<input name="link_url" value="<?=e($r['link_url'])?>">
<label>Sort order</label>
<input type="number" name="sort_order" value="<?=(int)$r['sort_order']?>">
<label style="display:inline-flex;gap:8px;align-items:center;font-weight:400">
<input type="checkbox" name="is_published" style="width:auto" <?=$r['is_published']?'checked':''?>> Published</label>
<button name="action" value="edit">SAVE CHANGES</button>
</form>
</div>
<?php
endforeach?>
</main>
</div>
<script>function toggleEdit(id){const f=document.getElementById('edit-form-'+id);f.style.display=f.style.display==='none'?'block':'none';}</script>
</body>
</html>
