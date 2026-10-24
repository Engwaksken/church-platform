<?php
declare(strict_types=1);

require_once '../config/config.php';
require_once '../includes/church-book-functions.php';

requireLogin();
cb_require_permission('converts.manage');

if (!isset($conn) || !($conn instanceof mysqli)) {
    die('Database connection not found.');
}

$conn->set_charset('utf8mb4');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ./');
    exit;
}

cb_verify_csrf();

$action = cb_clean($_POST['action'] ?? '');

function cn_redirect(string $anchor = ''): never
{
    header('Location: ./' . ($anchor !== '' ? '#' . ltrim($anchor, '#') : ''));
    exit;
}

function cn_member_name(mysqli $conn, ?int $memberId): string
{
    if (!$memberId || !cb_table_exists($conn, 'members')) {
        return '';
    }

    $row = cb_fetch_one(
        $conn,
        "SELECT full_name FROM members WHERE id=? LIMIT 1",
        'i',
        [$memberId]
    );

    return trim((string)($row['full_name'] ?? ''));
}

if ($action === 'save') {
    $id = cb_int($_POST['id'] ?? 0);
    $conversionDate = cb_clean($_POST['conversion_date'] ?? date('Y-m-d'));
    $personType = cb_clean($_POST['person_type'] ?? 'Visitor');
    $memberId = cb_nullable_int($_POST['member_id'] ?? null);
    $visitorId = cb_nullable_int($_POST['visitor_id'] ?? null);
    $fullName = cb_clean($_POST['full_name'] ?? '');
    $gender = cb_clean($_POST['gender'] ?? '');
    $phone = cb_clean($_POST['phone'] ?? '');
    $email = cb_clean($_POST['email'] ?? '');
    $address = cb_clean($_POST['address'] ?? '');
    $decisionType = cb_clean($_POST['decision_type'] ?? 'New Convert');
    $context = cb_clean($_POST['conversion_context'] ?? '');
    $testimony = cb_clean($_POST['testimony'] ?? '');
    $prayerRequest = cb_clean($_POST['prayer_request'] ?? '');
    $assignedMemberId = cb_nullable_int($_POST['assigned_member_id'] ?? null);
    $nurtureStart = cb_clean($_POST['nurture_start_date'] ?? '');
    $nurtureEnd = cb_clean($_POST['nurture_end_date'] ?? '');
    $periodWeeks = max(1, min(104, cb_int($_POST['nurture_period_weeks'] ?? 12, 12)));
    $status = cb_clean($_POST['status'] ?? 'Pending Assignment');
    $nextFollowUp = cb_clean($_POST['next_follow_up_date'] ?? '');
    $baptismInterest = isset($_POST['baptism_interest']) ? 1 : 0;
    $discipleshipClass = cb_clean($_POST['discipleship_class'] ?? '');
    $notes = cb_clean($_POST['notes'] ?? '');

    $allowedPersonTypes = ['Member', 'Visitor'];
    $allowedGender = ['Male', 'Female', 'Other', 'Prefer not to say'];
    $allowedDecisions = ['New Convert', 'Recommitment', 'Transferred In', 'Other'];
    $allowedStatuses = ['Pending Assignment', 'In Nurture', 'Paused', 'Completed', 'Transferred'];

    if (!in_array($personType, $allowedPersonTypes, true)) {
        $personType = 'Visitor';
    }
    if (!in_array($gender, $allowedGender, true)) {
        $gender = '';
    }
    if (!in_array($decisionType, $allowedDecisions, true)) {
        $decisionType = 'New Convert';
    }
    if (!in_array($status, $allowedStatuses, true)) {
        $status = $assignedMemberId ? 'In Nurture' : 'Pending Assignment';
    }

    if ($fullName === '') {
        cb_flash('Full name is required.', 'error');
        cn_redirect();
    }
    if (!cb_valid_date($conversionDate)) {
        cb_flash('Enter a valid conversion date.', 'error');
        cn_redirect();
    }
    foreach (['nurture_start_date' => $nurtureStart, 'nurture_end_date' => $nurtureEnd, 'next_follow_up_date' => $nextFollowUp] as $label => $value) {
        if ($value !== '' && !cb_valid_date($value)) {
            cb_flash('One of the nurture/follow-up dates is invalid.', 'error');
            cn_redirect();
        }
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        cb_flash('Enter a valid email address.', 'error');
        cn_redirect();
    }

    if ($personType === 'Member') {
        $visitorId = null;
    } else {
        $memberId = null;
    }

    if ($nurtureStart === '') {
        $nurtureStart = $conversionDate;
    }

    if ($nurtureEnd === '') {
        $dt = new DateTime($nurtureStart);
        $dt->modify('+' . $periodWeeks . ' weeks');
        $nurtureEnd = $dt->format('Y-m-d');
    }

    if (!$assignedMemberId && $status === 'In Nurture') {
        $status = 'Pending Assignment';
    } elseif ($assignedMemberId && $status === 'Pending Assignment') {
        $status = 'In Nurture';
    }

    $uid = cb_current_user_id();
    $genderDb = $gender !== '' ? $gender : null;
    $nurtureStartDb = $nurtureStart !== '' ? $nurtureStart : null;
    $nurtureEndDb = $nurtureEnd !== '' ? $nurtureEnd : null;
    $nextFollowUpDb = $nextFollowUp !== '' ? $nextFollowUp : null;

    if ($id > 0) {
        $stmt = $conn->prepare(
            "UPDATE church_converts SET
                conversion_date=?, person_type=?, member_id=?, visitor_id=?, full_name=?, gender=?, phone=?, email=?, address=?,
                decision_type=?, conversion_context=?, testimony=?, prayer_request=?, assigned_member_id=?, nurture_start_date=?,
                nurture_end_date=?, nurture_period_weeks=?, status=?, next_follow_up_date=?, baptism_interest=?, discipleship_class=?, notes=?
             WHERE id=?"
        );

        if (!$stmt) {
            cb_flash('Could not prepare convert update: ' . $conn->error, 'error');
            cn_redirect();
        }

        $stmt->bind_param(
            'ssiisssssssssissississi',
            $conversionDate,
            $personType,
            $memberId,
            $visitorId,
            $fullName,
            $genderDb,
            $phone,
            $email,
            $address,
            $decisionType,
            $context,
            $testimony,
            $prayerRequest,
            $assignedMemberId,
            $nurtureStartDb,
            $nurtureEndDb,
            $periodWeeks,
            $status,
            $nextFollowUpDb,
            $baptismInterest,
            $discipleshipClass,
            $notes,
            $id
        );

        $ok = $stmt->execute();
        $error = $stmt->error;
        $stmt->close();

        if (!$ok) {
            cb_flash('Could not update convert: ' . $error, 'error');
            cn_redirect();
        }

        cb_flash('Convert record updated successfully.');
        cn_redirect();
    }

    $stmt = $conn->prepare(
        "INSERT INTO church_converts (
            conversion_date, person_type, member_id, visitor_id, full_name, gender, phone, email, address,
            decision_type, conversion_context, testimony, prayer_request, assigned_member_id, nurture_start_date,
            nurture_end_date, nurture_period_weeks, status, next_follow_up_date, baptism_interest, discipleship_class,
            notes, self_registered, registration_link_id, created_by
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NULL,?)"
    );

    if (!$stmt) {
        cb_flash('Could not prepare convert registration: ' . $conn->error, 'error');
        cn_redirect();
    }

    $stmt->bind_param(
        'ssiisssssssssissississi',
        $conversionDate,
        $personType,
        $memberId,
        $visitorId,
        $fullName,
        $genderDb,
        $phone,
        $email,
        $address,
        $decisionType,
        $context,
        $testimony,
        $prayerRequest,
        $assignedMemberId,
        $nurtureStartDb,
        $nurtureEndDb,
        $periodWeeks,
        $status,
        $nextFollowUpDb,
        $baptismInterest,
        $discipleshipClass,
        $notes,
        $uid
    );

    $ok = $stmt->execute();
    $error = $stmt->error;
    $stmt->close();

    if (!$ok) {
        cb_flash('Could not add convert: ' . $error, 'error');
        cn_redirect();
    }

    cb_flash('Convert added and nurture plan created successfully.');
    cn_redirect();
}

if ($action === 'followup') {
    $convertId = cb_int($_POST['convert_id'] ?? 0);
    $followUpDate = cb_clean($_POST['follow_up_date'] ?? date('Y-m-d'));
    $contactMethod = cb_clean($_POST['contact_method'] ?? 'In Person');
    $progressStatus = cb_clean($_POST['progress_status'] ?? 'Growing');
    $topics = cb_clean($_POST['topics_discussed'] ?? '');
    $prayerNotes = cb_clean($_POST['prayer_notes'] ?? '');
    $nextFollowUp = cb_clean($_POST['next_follow_up_date'] ?? '');

    $methods = ['In Person', 'Phone Call', 'WhatsApp', 'SMS', 'Home Visit', 'Church Meeting', 'Other'];
    $progressOptions = ['Not Started', 'Growing', 'Needs Support', 'Ready to Complete', 'Completed'];

    if ($convertId <= 0 || !cb_valid_date($followUpDate)) {
        cb_flash('Select a valid convert and follow-up date.', 'error');
        cn_redirect();
    }
    if (!in_array($contactMethod, $methods, true)) {
        $contactMethod = 'In Person';
    }
    if (!in_array($progressStatus, $progressOptions, true)) {
        $progressStatus = 'Growing';
    }
    if ($nextFollowUp !== '' && !cb_valid_date($nextFollowUp)) {
        cb_flash('Enter a valid next follow-up date.', 'error');
        cn_redirect();
    }

    $convert = cb_fetch_one($conn, 'SELECT id FROM church_converts WHERE id=? LIMIT 1', 'i', [$convertId]);
    if (!$convert) {
        cb_flash('Convert record not found.', 'error');
        cn_redirect();
    }

    $nextDb = $nextFollowUp !== '' ? $nextFollowUp : null;
    $uid = cb_current_user_id();

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "INSERT INTO church_convert_followups
                (convert_id, follow_up_date, contact_method, progress_status, topics_discussed, prayer_notes, next_follow_up_date, recorded_by)
             VALUES (?,?,?,?,?,?,?,?)"
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare nurture follow-up.');
        }

        $stmt->bind_param(
            'issssssi',
            $convertId,
            $followUpDate,
            $contactMethod,
            $progressStatus,
            $topics,
            $prayerNotes,
            $nextDb,
            $uid
        );
        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to save nurture follow-up: ' . $stmt->error);
        }
        $stmt->close();

        $newStatus = $progressStatus === 'Completed' ? 'Completed' : null;

        if ($newStatus !== null) {
            $stmt = $conn->prepare('UPDATE church_converts SET next_follow_up_date=?, status=? WHERE id=?');
            if (!$stmt) {
                throw new RuntimeException('Unable to update convert progress.');
            }
            $stmt->bind_param('ssi', $nextDb, $newStatus, $convertId);
        } else {
            $stmt = $conn->prepare('UPDATE church_converts SET next_follow_up_date=? WHERE id=?');
            if (!$stmt) {
                throw new RuntimeException('Unable to update next follow-up date.');
            }
            $stmt->bind_param('si', $nextDb, $convertId);
        }

        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to update nurture plan: ' . $stmt->error);
        }
        $stmt->close();

        $conn->commit();
        cb_flash('Nurture follow-up recorded successfully.');
    } catch (Throwable $e) {
        $conn->rollback();
        cb_flash($e->getMessage(), 'error');
    }

    cn_redirect();
}

if ($action === 'delete') {
    $id = cb_int($_POST['id'] ?? 0);

    if ($id > 0) {
        $stmt = $conn->prepare('DELETE FROM church_converts WHERE id=?');
        if ($stmt) {
            $stmt->bind_param('i', $id);
            if ($stmt->execute()) {
                cb_flash('Convert record deleted.');
            } else {
                cb_flash('Could not delete convert: ' . $stmt->error, 'error');
            }
            $stmt->close();
        }
    }

    cn_redirect();
}

cb_flash('Invalid convert action.', 'error');
cn_redirect();
