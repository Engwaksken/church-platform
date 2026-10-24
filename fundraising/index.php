<?php
$pageTitle = 'Fundraising Campaigns';
require_once '../config/config.php';
if (function_exists('requireLogin')) { requireLogin(); }
if (!isset($conn) || !($conn instanceof mysqli)) { die('Database connection not found.'); }
$conn->set_charset('utf8mb4');
function frh($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$csrfToken = $_SESSION['csrf_token'] ?? $_SESSION['csrf'] ?? '';
?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>
<div class="main-content">
<?php include '../includes/topbar.php'; ?>
<div class="content-area fundraising-module">
<?php if (function_exists('showFlash')) echo showFlash(); ?>

<div class="page-header module-page-header">
  <div>
    <h2><i class="fas fa-bullhorn"></i> Fundraising Campaigns</h2>
    <p class="text-muted mb-0">Manage campaigns, collections, receipts, and progress.</p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <!-- Export group -->
    <div style="display:flex;gap:6px">
      <button type="button" class="btn btn-outline btn-sm" id="frBtnExportCSV" title="Export campaigns as CSV">
        <i class="fas fa-file-csv"></i> Export CSV
      </button>
      <button type="button" class="btn btn-outline btn-sm" id="frBtnExportPDF" title="Export campaigns as PDF">
        <i class="fas fa-file-pdf"></i> Export PDF
      </button>
    </div>
    <button type="button" class="btn btn-primary" data-fr-open="campaign">
      <i class="fas fa-plus"></i> New Campaign
    </button>
  </div>
</div>

<!-- Export filter bar (hidden until Export CSV/PDF is clicked) -->
<div id="frExportBar" class="fr-export-bar" style="display:none">
  <span style="font-weight:700;font-size:13px"><i class="fas fa-filter"></i> Export filters</span>
  <select id="frExportStatus" class="form-control" style="width:auto;min-width:160px">
    <option value="">All Statuses</option>
    <option value="Planning">Planning</option>
    <option value="Active">Active</option>
    <option value="Completed">Completed</option>
    <option value="Cancelled">Cancelled</option>
  </select>
  <button type="button" class="btn btn-primary btn-sm" id="frBtnExportBarGo">
    <i class="fas fa-download"></i> Download
  </button>
  <button type="button" class="btn btn-outline btn-sm" id="frBtnExportBarClose">
    <i class="fas fa-times"></i>
  </button>
</div>

<div class="module-stats-grid stats-grid three-per-row">
  <div class="stat-card"><div class="stat-icon"><i class="fas fa-bullhorn"></i></div><div><div class="stat-value" id="frStatCampaigns">0</div><div class="stat-label">Campaigns</div></div></div>
  <div class="stat-card"><div class="stat-icon"><i class="fas fa-play-circle"></i></div><div><div class="stat-value" id="frStatActive">0</div><div class="stat-label">Active</div></div></div>
  <div class="stat-card"><div class="stat-icon"><i class="fas fa-donate"></i></div><div><div class="stat-value" id="frStatCollections">0</div><div class="stat-label">Collections</div></div></div>
  <div class="stat-card"><div class="stat-icon"><i class="fas fa-coins"></i></div><div><div class="stat-value" id="frStatRaised">UGX 0</div><div class="stat-label">Raised</div></div></div>
  <div class="stat-card"><div class="stat-icon"><i class="fas fa-flag-checkered"></i></div><div><div class="stat-value" id="frStatTarget">UGX 0</div><div class="stat-label">Target</div></div></div>
  <div class="stat-card"><div class="stat-icon"><i class="fas fa-chart-line"></i></div><div><div class="stat-value" id="frStatPercent">0%</div><div class="stat-label">Overall Progress</div></div></div>
</div>

<div class="card">
  <div class="card-body">
    <div class="page-toolbar">
      <div class="page-toolbar-search">
        <i class="fas fa-search"></i>
        <input type="text" id="frSearch" class="form-control" placeholder="Search campaign, organizer, venue, status...">
      </div>
      <div class="page-toolbar-filters">
      <select id="frStatusFilter" class="form-control">
        <option value="">All Statuses</option>
        <option value="Planning">Planning</option>
        <option value="Active">Active</option>
        <option value="Completed">Completed</option>
        <option value="Cancelled">Cancelled</option>
      </select>
      </div>
      <div class="page-toolbar-actions">
        <button type="button" class="btn btn-outline-primary page-toolbar-refresh" id="frRefresh"><i class="fas fa-rotate-right"></i> Refresh</button>
      </div>
    </div>
    <div id="frAlert" class="ajax-alert"></div>
    <div id="frCampaignGrid" class="fundraising-grid">
      <div class="text-muted p-3">Loading campaigns...</div>
    </div>
  </div>
</div>

<!-- ======================================================
     MODALS
====================================================== -->
<div class="ajax-modal-backdrop" id="frBackdrop"></div>

<!-- Campaign Form Modal -->
<div class="ajax-modal" id="frCampaignModal" aria-hidden="true">
  <div class="ajax-dialog ajax-dialog-lg">
    <form id="frCampaignForm">
      <div class="ajax-modal-header">
        <h4 id="frCampaignTitle">New Campaign</h4>
        <button type="button" class="modal-close" data-fr-close>&times;</button>
      </div>
      <div class="ajax-modal-body">
        <div id="frCampaignAlert" class="ajax-alert"></div>
        <input type="hidden" name="csrf_token" value="<?=frh($csrfToken)?>">
        <input type="hidden" name="action" value="save_campaign">
        <input type="hidden" name="id" id="frCampaignId">
        <div class="form-grid two-col">
          <div class="form-group full"><label>Campaign Title *</label><input type="text" name="title" id="frTitle" class="form-control" required></div>
          <div class="form-group full"><label>Description</label><textarea name="description" id="frDescription" class="form-control" rows="3"></textarea></div>
          <div class="form-group"><label>Target Amount (UGX)</label><input type="number" step="0.01" name="target_amount" id="frTarget" class="form-control" value="0"></div>
          <div class="form-group"><label>Status</label><select name="status" id="frStatus" class="form-control"><option>Planning</option><option>Active</option><option>Completed</option><option>Cancelled</option></select></div>
          <div class="form-group"><label>Start Date</label><input type="date" name="start_date" id="frStart" class="form-control" value="<?=date('Y-m-d')?>"></div>
          <div class="form-group"><label>End Date</label><input type="date" name="end_date" id="frEnd" class="form-control"></div>
          <div class="form-group"><label>Venue</label><input type="text" name="venue" id="frVenue" class="form-control"></div>
          <div class="form-group"><label>Organizer</label><input type="text" name="organizer" id="frOrganizer" class="form-control"></div>
        </div>
      </div>
      <div class="ajax-modal-footer">
        <button type="button" class="btn btn-outline" data-fr-close>Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Campaign</button>
      </div>
    </form>
  </div>
</div>

<!-- View Campaign Modal -->
<div class="ajax-modal" id="frViewModal" aria-hidden="true">
  <div class="ajax-dialog ajax-dialog-xl">
    <div class="ajax-modal-header">
      <h4><i class="fas fa-bullhorn"></i> Campaign Details</h4>
      <button type="button" class="modal-close" data-fr-close>&times;</button>
    </div>
    <div class="ajax-modal-body">
      <div id="frViewContent"></div>
      <hr>
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
        <h5 class="mb-0">Collections</h5>
        <div style="display:flex;gap:6px;flex-wrap:wrap">
          <button type="button" class="btn btn-outline btn-sm" id="frBtnExportCollectionsCSV" title="Export this campaign's collections as CSV">
            <i class="fas fa-file-csv"></i> CSV
          </button>
          <button type="button" class="btn btn-outline btn-sm" id="frBtnExportCollectionsPDF" title="Export this campaign's collections as PDF">
            <i class="fas fa-file-pdf"></i> PDF
          </button>
          <button type="button" class="btn btn-success btn-sm" id="frViewAddCollection">
            <i class="fas fa-plus"></i> Add Collection
          </button>
        </div>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead><tr><th>#</th><th>Donor</th><th>Amount</th><th>Method</th><th>Date</th><th>Item</th><th>Actions</th></tr></thead>
          <tbody id="frCollectionsBody"><tr><td colspan="7" class="text-muted">Loading...</td></tr></tbody>
        </table>
      </div>
    </div>
    <div class="ajax-modal-footer">
      <button type="button" class="btn btn-outline" data-fr-close>Close</button>
    </div>
  </div>
</div>

<!-- Collection Form Modal -->
<div class="ajax-modal" id="frCollectionModal" aria-hidden="true">
  <div class="ajax-dialog ajax-dialog-lg">
    <form id="frCollectionForm">
      <div class="ajax-modal-header">
        <h4 id="frCollectionTitle"><i class="fas fa-donate"></i> Record Collection</h4>
        <button type="button" class="modal-close" data-fr-close>&times;</button>
      </div>
      <div class="ajax-modal-body">
        <div id="frCollectionAlert" class="ajax-alert"></div>
        <input type="hidden" name="csrf_token" value="<?=frh($csrfToken)?>">
        <input type="hidden" name="action" value="save_collection">
        <input type="hidden" name="id" id="frCollectionId">
        <input type="hidden" name="campaign_id" id="frCollectionCampaignId">
        <div class="form-grid two-col">
          <div class="form-group full"><label>Campaign</label><input type="text" id="frCollectionCampaignName" class="form-control" readonly></div>
          <div class="form-group"><label>Church Member</label><select name="member_id" id="frMemberId" class="form-control"><option value="">Visitor / Non-member</option></select></div>
          <div class="form-group"><label>Donor Name</label><input type="text" name="donor_name" id="frDonorName" class="form-control" placeholder="Required for visitor/non-member"></div>
          <div class="form-group"><label>Amount (UGX)</label><input type="number" step="0.01" name="amount" id="frAmount" class="form-control" value="0"></div>
          <div class="form-group"><label>Collection Date</label><input type="date" name="collection_date" id="frCollectionDate" class="form-control" value="<?=date('Y-m-d')?>"></div>
          <div class="form-group"><label>Payment Method</label><select name="payment_method" id="frPaymentMethod" class="form-control"><option>Cash</option><option>Mobile Money</option><option>Bank Transfer</option><option>Cheque</option><option>Kind</option></select></div>
          <div class="form-group full"><label>Item Description / In-kind Donation</label><input type="text" name="item_description" id="frItemDescription" class="form-control"></div>
          <div class="form-group full"><label>Notes</label><textarea name="notes" id="frNotes" class="form-control" rows="2"></textarea></div>
        </div>
      </div>
      <div class="ajax-modal-footer">
        <button type="button" class="btn btn-outline" data-fr-close>Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Collection</button>
      </div>
    </form>
  </div>
</div>

<!-- Confirm Delete Modal -->
<div class="ajax-modal" id="frConfirmModal" aria-hidden="true">
  <div class="ajax-dialog ajax-dialog-sm">
    <form id="frConfirmForm">
      <div class="ajax-modal-header">
        <h4><i class="fas fa-trash"></i> Confirm Delete</h4>
        <button type="button" class="modal-close" data-fr-close>&times;</button>
      </div>
      <div class="ajax-modal-body">
        <div id="frConfirmAlert" class="ajax-alert"></div>
        <input type="hidden" name="csrf_token" value="<?=frh($csrfToken)?>">
        <input type="hidden" name="action" id="frConfirmAction">
        <input type="hidden" name="id" id="frConfirmId">
        <p id="frConfirmText">Are you sure?</p>
      </div>
      <div class="ajax-modal-footer">
        <button type="button" class="btn btn-outline" data-fr-close>Cancel</button>
        <button type="submit" class="btn btn-danger"><i class="fas fa-trash"></i> Delete</button>
      </div>
    </form>
  </div>
</div>

<style>
.fr-export-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 14px;
    padding: 10px 16px;
    background: #f5f1ff;
    border: 1px solid #c4b5fd;
    border-radius: 12px;
}
</style>

<link rel="stylesheet" href="fundraising.css?v=<?=time()?>">
<script>
window.FundraisingConfig = {
    endpoint : 'process-fundraising.php',
    today    : '<?=date('Y-m-d')?>'
};
</script>
<script src="fundraising.js?v=<?=time()?>"></script>

</div>
</div>
<?php include '../includes/footer.php'; ?>