<?php
declare(strict_types=1);
function mp_render_listing_page(string $heading,string $note,array $allRows,array $stats,array $labels=[]): void {
    $search=trim((string)($_GET['search']??''));$filtered=mp_filter_rows($allRows,$search);$page=(int)($_GET['page']??1);$per=(int)($_GET['per_page']??10);$pagination=mp_paginate_rows($filtered,$page,$per);
    $token=mp_make_pdf_token($heading,$filtered,$labels);$pdf=BASE_URL.'member/export-pdf.php?token='.rawurlencode($token);
    echo '<div class="member-note">'.mp_h($note).'</div>';mp_render_stats($stats);mp_render_toolbar($pdf);mp_render_table($pagination['rows'],$labels);mp_render_pagination($pagination);
}
