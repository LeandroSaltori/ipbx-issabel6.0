<?php
/**
 * IPbx Prisma - Sincronização da Agenda
 */

class AddressBookSync {
    public static function syncToIssabel($name, $phone, $email = '', $company = '', $notes = '') {
        return true;
    }

    public static function deleteFromIssabel($phone) {
        return true;
    }

    public static function syncIssabelToFront($db, $force = false) {
        return 0;
    }

    public static function syncFrontToIssabelAll($db) {
        return 0;
    }

    public static function getVersionHash($db) {
        if (!$db) return '0';
        try {
            $stmt = $db->query("SELECT COUNT(*) as total, MAX(updated_at) as max_up FROM crm_contacts");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return md5(($row['total'] ?? 0) . '-' . ($row['max_up'] ?? ''));
        } catch (Exception $e) {
            return '0';
        }
    }
}
