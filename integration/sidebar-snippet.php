<?php
// Add these only in the ADMIN/STAFF navigation.
// People & Registers:
navLink(BASE_URL . 'visitors/', 'fa-address-book', 'Church Visitors Book', $dir, $cur, 'visitors', 'visitors.view');
navLink(BASE_URL . 'wedding_vows/', 'fa-ring', 'Wedding Vows Book', $dir, $cur, 'wedding_vows', 'wedding_vows.view');

// Recommended placement: after People & Registers, or under a new "Discipleship & Follow-up" section.
navLink(BASE_URL . 'converts/', 'fa-seedling', 'Converts & Nurture', $dir, $cur, 'converts', 'converts.view');
