<?php
require_once __DIR__.'/../includes/bootstrap.php';
require_role('lawyer');
redirect('/LegalEase_eProject/lawyer/profile.php?tab=expertise#verification');
?>
