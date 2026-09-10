<?php
$active_page = $active_page ?? '';
?>
<aside class="sidebar">
<div class="brand">
<div class="brand-mark">⚖</div>
<div>
<strong>LegalEase</strong>
<span>Admin Portal</span>
</div>
</div>
<nav class="sidebar-nav">
<a href="dashboard.php" class="<?= $active_page === 'dashboard' ? 'active' : ''?>">
<span>◔</span> Dashboard</a>
<a href="bookings.php" class="<?= $active_page === 'bookings' ? 'active' : ''?>">
<span>▣</span> Booking Appointments</a>
<a href="lawyers.php" class="<?= $active_page === 'lawyers' ? 'active' : ''?>">
<span>⚖</span> Lawyer Management</a>
<a href="users.php" class="<?= $active_page === 'users' ? 'active' : ''?>">
<span>👤</span> User Management</a>
<a href="reports.php" class="<?= $active_page === 'reports' ? 'active' : ''?>">
<span>📊</span> Reporting &amp; Analytics</a>
<a href="content.php" class="<?= $active_page === 'content' ? 'active' : ''?>">
<span>📄</span> Content Management</a>
</nav>
<a href="../logout.php" class="logout-btn">↪ Logout</a>
</aside>
