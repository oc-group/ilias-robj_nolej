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
 * Nolej notification repository
 * (using user preferences)
 */
class ilNolejNotificationPrefRepository
{
    /**
     * @var ilObjUser
     */
    protected ilObjUser $user;

    /**
     * Constructor
     *
     * @param ilObjUser|null $user
     */
    public function __construct(?ilObjUser $user = null)
    {
        global $DIC;

        $this->user = (is_null($user))
            ? $DIC->user()
            : $user;
    }

    /**
     * Set last checked timestamp
     */
    public function updateLastCheckedTimestamp(): void
    {
        $this->user->writePref("nolej_last_checked", (string) time());
    }

    /**
     * Get last checked timestamp
     *
     * @return int
     */
    public function getLastCheckedTimestamp(): int
    {
        return (int) $this->user->getPref("nolej_last_checked");
    }
}
