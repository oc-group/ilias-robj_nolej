<?php

/**
 * This file is part of Nolej Repository Object Plugin for ILIAS,
 * developed by OC Open Consulting to integrate ILIAS with Nolej
 * software by Neuronys.
 *
 * @author Vincenzo Padula <vincenzo@oc-group.eu>
 * @copyright 2024 OC Open Consulting SB Srl
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

/**
 * Please do not create instances of large application classes
 * Write small methods within this class to determine the status.
 */
class ilObjNolejAccess extends ilObjectPluginAccess
{
    /**
     * Checks whether a user may invoke a command or not
     * (this method is called by ilAccessHandler::checkAccess)
     *
     * Please do not check any preconditions handled by
     * ilConditionHandler here. Also don't do usual RBAC checks.
     *
     * @param string $a_cmd command (not permission!)
     * @param string $a_permission permission
     * @param int $a_ref_id reference id
     * @param int $a_obj_id object id
     * @param ?int $a_user_id user id (default is current user)
     * @return bool true, if everything is ok
     */
    public function _checkAccess(
        string $a_cmd,
        string $a_permission,
        int $a_ref_id,
        int $a_obj_id,
        ?int $a_user_id = null
    ): bool {
        if ($a_user_id == null) {
            $a_user_id = $this->user->getId();
        }

        if (empty($a_permission)) {
            return false;
        }

        switch ($a_permission) {
            case "visible":
            case "read":
                // A user who can edit the object can also see it.
                if ($this->access->checkAccessOfUser($a_user_id, "write", "", $a_ref_id)) {
                    return true;
                }

                if (self::_isOffline($a_obj_id)) {
                    return false;
                }

                return $this->access->checkAccessOfUser($a_user_id, $a_permission, "", $a_ref_id);

            case "delete":
            case "write":
            case "edit_permission":
            default:
                return $this->access->checkAccessOfUser($a_user_id, $a_permission, "", $a_ref_id);
        }
    }

    /**
     * @param int $a_obj_id
     * @return bool
     */
    public static function _isOffline(int $a_obj_id): bool
    {
        global $DIC;
        $db = $DIC->database();

        $set = $db->queryF(
            "SELECT is_online FROM " . ilNolejPlugin::TABLE_DATA . " WHERE id = %s;",
            ["integer"],
            [$a_obj_id]
        );
        $rec = $db->fetchAssoc($set);
        return !((bool) ($rec["is_online"] ?? false));
    }

    /**
     * Returns an array with valid operators for the specific object type
     * @return string[]
     */
    public static function getConditionOperators(): array
    {
        return [
            ilConditionHandler::OPERATOR_FAILED,
            ilConditionHandler::OPERATOR_PASSED
        ];
    }

    /**
     * Goto redirection
     * @param string $a_target
     * @return bool
     */
    public static function _checkGoto(string $a_target): bool
    {
        $target = substr($a_target, strlen(ilNolejPlugin::PLUGIN_ID) + 1); // Remove plugin ID.

        if ($target == "webhook") {
            return true;
        }

        return parent::_checkGoto($a_target);
    }

    /**
     * @inheritDoc
     */
    public function canBeDelivered(ilWACPath $ilWACPath): bool
    {
        // No protected path is registered in the web access check.
        // This method is never asked, so nothing is delivered.
        return false;
    }
}
