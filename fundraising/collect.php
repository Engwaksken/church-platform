<?php
require_once '../config/config.php'; requireLogin();
$cid = intval($_GET['id']??0);
$campaign = fetchOne("SELECT * FROM fundraising WHERE id=$cid");
if(!$campaign){ setFlash('Campaign not found.','danger'); redirect('index.php'); }
$members = mysqli_query($conn,"SELECT id,full_name,member_no FROM members ORDER BY full_name");
if($_SERVER['REQUEST_METHOD']==='POST'){
    $no     = genNo('FRC');
    $mid    = intval($_POST['member_id'])||'NULL';
    $donor  = san($_POST['donor_name']);
    $amt    = floatval($_POST['amount']);
    $date   = san($_POST['collection_date']);
    $method = san($_POST['payment_method']);
    $item   = san($_POST['item_description']);
    $notes  = san($_POST['notes']);
    mysqli_query($conn,"INSERT INTO fundraising_collections (campaign_id,receipt_no,member_id,donor_name,amount,collection_date,payment_method,item_description,notes) VALUES ($cid,'$no',$mid,'$donor',$amt,'$date','$method','$item','$notes')");
    setFlash("Collection of UGX ".number_format($amt)." recorded.",'success');
    if(isset($_POST['add_another'])) redirect("collect.php?id=$cid");
    else redirect("view.php?id=$cid");
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">
<?php echo showFlash(); ?>
<div class="page-header">
  <h2><i class="fas fa-plus"></i> Add Collection &mdash; <?=san($campaign['title'])?></h2>
  <a href="view.php?id=<?=$cid?>" class="btn btn-secondary">Back to Campaign</a>
</div>
<div class="card"><div class="card-body">
<form method="POST">
<div class="form-row">
  <div class="form-group col-md-6"><label>Church Member (if registered)</label>
    <select name="member_id" class="form-control">
      <option value="">-- Visitor / Non-member --</option>
      <?php while($m=mysqli_fetch_assoc($members)): ?>
      <option value="<?=$m['id']?>"><?=san($m['full_name'])?> (<?=san($m['member_no'])?>)</option>
      <?php endwhile; ?>
    </select>
  </div>
  <div class="form-group col-md-6"><label>Donor Name (if not a member)</label><input type="text" name="donor_name" class="form-control"></div>
</div>
<div class="form-row">
  <div class="form-group col-md-4"><label>Amount (UGX) *</label><input type="number" name="amount" class="form-control" step="0.01" required></div>
  <div class="form-group col-md-4"><label>Date *</label><input type="date" name="collection_date" class="form-control" value="<?=date('Y-m-d')?>" required></div>
  <div class="form-group col-md-4"><label>Payment Method</label>
    <select name="payment_method" class="form-control">
      <option>Cash</option><option>Mobile Money</option><option>Bank Transfer</option><option>Cheque</option><option>Kind</option>
    </select>
  </div>
</div>
<div class="form-group"><label>Item (if Kind/In-kind donation)</label><input type="text" name="item_description" class="form-control"></div>
<div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
<div class="d-flex gap-2">
  <button type="submit" class="btn btn-primary mr-2"><i class="fas fa-save"></i> Save &amp; Return</button>
  <button type="submit" name="add_another" value="1" class="btn btn-success"><i class="fas fa-plus"></i> Save &amp; Add Another</button>
</div>
</form>
</div></div>
</div>
</div>
<?php include '../includes/footer.php'; ?>
