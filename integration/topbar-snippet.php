<?php
// Safe integration helper. Include after $directoryTitles is initialized,
// or copy these entries into that array.
$directoryTitles = array_merge($directoryTitles ?? [], [
    'visitors' => 'Church Visitors Book',
    'wedding_vows' => 'Wedding Vows Book',
    'converts' => 'Converts & Nurture',
]);
