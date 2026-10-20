<?php
require_once '../config/config.php'; requireLogin();
$id = intval($_GET['id']??0);
$rec = $id ? fetchOne("SELECT * FROM thanksgiving WHERE id=$id") : null;
$members = mysqli_query($conn,"SELECT id, CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) AS full_name,member_no FROM members ORDER BY first_name");
if($_SERVER['REQUEST_METHOD']==='POST'){
    $no     = $id ? san($_POST['receipt_no']) : genNo('TGV');
    $mid    = intval($_POST['member_id'])||'NULL';
    $giver  = san($_POST['giver_name']);
    $reason = san($_POST['reason']);
    $amt    = floatval($_POST['amount']);
    $date   = san($_POST['thanksgiving_date']);
    $method = san($_POST['payment_method']);
    $item   = san($_POST['item_description']);
    $notes  = san($_POST['notes']);
    if($id){
        mysqli_query($conn,"UPDATE thanksgiving SET member_id=$mid,giver_name='$giver',reason='$reason',amount=$amt,thanksgiving_date='$date',payment_method='$method',item_description='$item',notes='$notes' WHERE id=$id");
        setFlash('Thanksgiving updated.','success');
    } else {
        mysqli_query($conn,"INSERT INTO thanksgiving (receipt_no,member_id,giver_name,reason,amount,thanksgiving_date,payment_method,item_description,notes) VALUES ('$no',$mid,'$giver','$reason',$amt,'$date','$method','$item','$notes')");
        setFlash("Thanksgiving recorded. Receipt: <strong>$no</strong>",'success');
    }
    redirect('index.php');
}
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area">
<?php echo showFlash(); ?>
<div class="page-header"><h2><i class="fas fa-praying-hands"></i> <?=$id?'Edit':'Record'?> Thanksgiving</h2><a href="index.php" class="btn btn-secondary">Back</a></div>
<div class="card"><div class="card-body">
<form method="POST">
<?php if($id): ?><input type="hidden" name="receipt_no" value="<?=san($rec['receipt_no'])?>"> <?php endif; ?>
<div class="form-row">
  <div class="form-group col-md-6"><label>Church Member (if registered)</label>
    <select name="member_id" class="form-control">
      <option value="">-- Visitor / Non-member --</option>
      <?php while($m=mysqli_fetch_assoc($members)): ?>
      <option value="<?=$m['id']?>" <?=($rec['member_id']??'')==$m['id']?'selected':''?>><?=san($m['full_name'])?> (<?=san($m['member_no'])?>)</option>
      <?php endwhile; ?>
    </select>
  </div>
  <div class="form-group col-md-6"><label>Giver's Name (if not a member)</label><input type="text" name="giver_name" class="form-control" value="<?=san($rec['giver_name']??'')?>"></div>
</div>
<div class="form-group"><label>Reason for Thanksgiving *</label><input type="text" name="reason" class="form-control" placeholder="e.g. Recovery from illness, New baby, New job, Safe journey..." value="<?=san($rec['reason']??'')?>" required></div>
<div class="form-row">
  <div class="form-group col-md-4"><label>Amount (UGX) *</label><input type="number" name="amount" class="form-control" value="<?=$rec['amount']??''?>" step="0.01" required></div>
  <div class="form-group col-md-4"><label>Date *</label><input type="date" name="thanksgiving_date" class="form-control" value="<?=san($rec['thanksgiving_date']??date('Y-m-d'))?>" required></div>
  <div class="form-group col-md-4"><label>Payment Method</label>
    <select name="payment_method" class="form-control">
      <?php foreach(['Cash','Mobile Money','Bank Transfer','Cheque','Kind'] as $mt): ?>
      <option <?=($rec['payment_method']??'Cash')==$mt?'selected':''?>><?=$mt?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>
<div class="form-group"><label>Item Description (if given in Kind)</label><input type="text" name="item_description" class="form-control" value="<?=san($rec['item_description']??'')?>" placeholder="e.g. 1 bag of rice, 2 jerry cans of oil"></div>
<div class="form-group"><label>Notes</label><textarea name="notes" class="form-control" rows="2"><?=san($rec['notes']??'')?></textarea></div>
<button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Thanksgiving</button>
</form>
</div></div>
</div>
</div>
<?php include '../includes/footer.php'; ?>
