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
 * Set and retrieve user notification about Nolej
 * activity generation progresses.
 */
class ilNolejActivity
{
    /** @var ilDBInterface */
    protected ilDBInterface $db;

    /** @var ilNolejPlugin */
    protected ilNolejPlugin $plugin;

    /** @var string|null */
    protected ?string $document_id = null;

    /** @var int|null */
    protected ?int $user_id = null;

    /** @var string|null */
    protected ?string $action = null;

    /** @var int|null */
    protected ?int $tstamp = null;

    /** @var string|null */
    protected ?string $status = null;

    /** @var int|null */
    protected ?int $code = null;

    /** @var string|null */
    protected ?string $error_message = null;

    /** @var int|null */
    protected ?int $consumed_credit = null;

    /** @var string y|n */
    protected string $notified = "n";

    /** @var bool */
    protected bool $stored = false;

    /** @var int|null */
    protected ?int $objId = null;

    /** @var int|null */
    protected ?int $refId = null;

    /**
     * @param string|null $a_doc_id
     * @param int|null $a_user_id
     * @param string|null $a_action
     */
    public function __construct(
        ?string $a_doc_id = null,
        ?int $a_user_id = null,
        ?string $a_action = null
    ) {
        global $DIC;

        $this->db = $DIC->database();
        $this->plugin = ilNolejPlugin::getInstance();

        if ($a_doc_id && $a_user_id && $a_action) {
            $this->setDocumentId($a_doc_id);
            $this->setUserId($a_user_id);
            $this->setAction($a_action);

            $this->read($a_doc_id, $a_user_id, $a_action);
        }
    }

    /**
     * Get new counter
     *
     * @param int $a_user_id
     * @return int
     */
    public static function getNewCounter(int $a_user_id): int
    {
        global $DIC;

        $db = $DIC->database();

        $user = new ilObjUser($a_user_id);
        $noti_repo = new ilNolejNotificationPrefRepository($user);

        $last = $noti_repo->getLastCheckedTimestamp();

        // If no last check exists, we use last 24 hours
        if ($last == 0) {
            $last = time() - (24 * 60 * 60);
        }

        if ($last > 0) {
            $set = $db->queryF(
                "SELECT count(*) cnt FROM " . ilNolejPlugin::TABLE_ACTIVITY
                . " WHERE user_id = %s AND tstamp >= %s",
                ["integer", "integer"],
                [$a_user_id, $last]
            );
            $rec = $db->fetchAssoc($set);
            return (int) $rec["cnt"];
        }

        return 0;
    }

    /**
     * Get latest activity timestamp
     *
     * @param int $a_user_id
     * @return int
     */
    public static function getLatestTimestamp(int $a_user_id): int
    {
        global $DIC;

        $db = $DIC->database();

        $set = $db->queryF(
            "SELECT max(tstamp) maxts FROM " . ilNolejPlugin::TABLE_ACTIVITY
            . " WHERE user_id = %s",
            ["integer"],
            [$a_user_id]
        );
        $rec = $db->fetchAssoc($set);
        return (int) $rec["maxts"];
    }

    /**
     * Get notifications for a user
     * @param int $a_user_id
     * @return self[]
     */
    public static function getInstancesByUserId(int $a_user_id): array
    {
        global $DIC;

        $ilDB = $DIC->database();

        $res = [];

        $set = $ilDB->query(
            "SELECT * FROM " . ilNolejPlugin::TABLE_ACTIVITY
            . " WHERE user_id = " . $ilDB->quote($a_user_id, "integer")
            . " ORDER BY tstamp DESC"
        );
        while ($row = $ilDB->fetchAssoc($set)) {
            $obj = new self();
            $obj->importDBRow($row);
            $res[] = $obj;
        }

        return $res;
    }

    /**
     * @param string $a_doc_id
     * @return self[]
     */
    public static function getInstancesByDocumentId(string $a_doc_id): array
    {
        global $DIC;

        $ilDB = $DIC->database();

        $res = [];

        $set = $ilDB->queryF(
            "SELECT * FROM " . ilNolejPlugin::TABLE_ACTIVITY
            . " WHERE document_id = %s",
            ["text"],
            [$a_doc_id]
        );
        while ($row = $ilDB->fetchAssoc($set)) {
            $obj = new self();
            $obj->importDBRow($row);
            $res[] = $obj;
        }

        return $res;
    }

    /**
     * @param string $a_doc_id
     * @return int[]
     */
    public static function getAssignedUsers(string $a_doc_id): array
    {
        $res = [];

        foreach (self::getInstancesByDocumentId($a_doc_id) as $ass) {
            $userId = $ass->getUserId();
            if ($userId !== null) {
                $res[] = $userId;
            }
        }

        return $res;
    }

    /**
     * @param string $a_doc_id
     * @param int $a_user_id
     * @param string $a_action
     * @return bool
     */
    public static function exists(string $a_doc_id, int $a_user_id, string $a_action): bool
    {
        $obj = new self($a_doc_id, $a_user_id, $a_action);
        return $obj->stored;
    }

    //
    // setter/getter
    //

    /**
     * @param string $a_value
     */
    protected function setDocumentId(string $a_value): void
    {
        $this->document_id = $a_value;
    }

    /**
     * @return string|null
     */
    public function getDocumentId(): ?string
    {
        return $this->document_id;
    }

    /**
     * @param int $a_value
     * @return void
     */
    protected function setUserId(int $a_value): void
    {
        $this->user_id = $a_value;
    }

    /**
     * @return int|null
     */
    public function getUserId(): ?int
    {
        return $this->user_id;
    }

    /**
     * @param string $a_value
     */
    protected function setAction(string $a_value): void
    {
        $this->action = $a_value;
    }

    /**
     * @return string|null
     */
    public function getAction(): ?string
    {
        return $this->action;
    }

    /**
     * @param int $a_value
     */
    protected function setTimestamp(int $a_value): void
    {
        $this->tstamp = $a_value;
    }

    /**
     * @return int|null
     */
    public function getTimestamp(): ?int
    {
        return $this->tstamp;
    }

    /**
     * @param string $a_status
     * @return self
     */
    public function withStatus(string $a_status): self
    {
        $this->status = $a_status;
        return $this;
    }

    /**
     * @param int $a_code
     * @return self
     */
    public function withCode(int $a_code): self
    {
        $this->code = $a_code;
        return $this;
    }

    /**
     * @param string $a_error_message
     * @return self
     */
    public function withErrorMessage(string $a_error_message): self
    {
        $this->error_message = $a_error_message;
        return $this;
    }

    /**
     * @param int $a_consumed_credit
     * @return self
     */
    public function withConsumedCredit(int $a_consumed_credit): self
    {
        $this->consumed_credit = $a_consumed_credit;
        return $this;
    }

    /**
     * Create a notification object given its associative array
     * @param array<string, mixed> $a_row
     * @return void
     */
    protected function importDBRow(array $a_row): void
    {
        $this->stored = true;
        $this->setDocumentId((string) $a_row["document_id"]);
        $this->setUserId((int) $a_row["user_id"]);
        $this->setAction((string) $a_row["action"]);
        $this->setTimestamp((int) $a_row["tstamp"]);
        $this->status = isset($a_row["status"]) ? (string) $a_row["status"] : null;
        $this->code = (int) $a_row["code"];
        $this->error_message = (string) $a_row["error_message"];
        $this->consumed_credit = isset($a_row["consumed_credit"]) ? (int) $a_row["consumed_credit"] : null;
        $this->notified = (string) $a_row["notified"];
    }

    /**
     * @param string $a_doc_id
     * @param int $a_user_id
     * @param string $a_action
     * @return void
     */
    protected function read(string $a_doc_id, int $a_user_id, string $a_action): void
    {
        $ilDB = $this->db;

        $set = $ilDB->queryF(
            "SELECT * FROM " . ilNolejPlugin::TABLE_ACTIVITY
            . " WHERE document_id = %s AND user_id = %s AND action = %s",
            ["text", "integer", "text"],
            [$a_doc_id, $a_user_id, $a_action]
        );
        $row = $ilDB->fetchAssoc($set);
        if (is_array($row) && $row["user_id"]) {
            $this->importDBRow($row);
            return;
        }

        $this->setTimestamp(time());
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    protected function getPropertiesForStorage(): array
    {
        return [
            "tstamp" => [
                "integer",
                $this->stored ? $this->getTimestamp() : time()
            ],
            "status" => [
                "text",
                $this->status
            ],
            "code" => [
                "integer",
                $this->code
            ],
            "error_message" => [
                "text",
                $this->error_message
            ],
            "consumed_credit" => [
                "integer",
                $this->consumed_credit
            ],
            "notified" => [
                "text",
                $this->notified
            ]
        ];
    }

    /**
     * @return void
     */
    public function store(): void
    {
        global $DIC;
        $ilDB = $this->db;

        if (
            !$this->getDocumentId() ||
            !$this->getUserId() ||
            !$this->getAction()
        ) {
            $DIC->ui()->mainTemplate()->setOnScreenMessage("failure", "Some value null", true);
            $this->plugin->log("Notification: some values are null: " . (string) json_encode([
                "documentId" => $this->getDocumentId(),
                "userId" => $this->getUserId(),
                "action" => $this->getAction()
            ]));
            return;
        }

        $keys = [
            "document_id" => ["text", $this->getDocumentId()],
            "user_id" => ["integer", $this->getUserId()],
            "action" => ["text", $this->getAction()]
        ];
        $fields = $this->getPropertiesForStorage();

        if (!$this->stored) {
            $ilDB->insert(ilNolejPlugin::TABLE_ACTIVITY, $fields + $keys);
            return;
        }

        $ilDB->update(ilNolejPlugin::TABLE_ACTIVITY, $fields, $keys);
    }

    /**
     * @return void
     */
    public function delete(): void
    {
        $ilDB = $this->db;

        if (
            !$this->getDocumentId() ||
            !$this->getUserId() ||
            !$this->getAction()
        ) {
            return;
        }

        $ilDB->manipulateF(
            "UPDATE " . ilNolejPlugin::TABLE_ACTIVITY
            . " SET notified = 'y' WHERE document_id = %s AND user_id = %s",
            ["text", "integer"],
            [$this->getDocumentId(), $this->getUserId()]
        );
    }

    /**
     * @param int $a_user_id
     * @return void
     */
    public static function deleteByUserId(int $a_user_id): void
    {
        foreach (self::getInstancesByUserId($a_user_id) as $ass) {
            $ass->delete();
        }
    }

    /**
     * @param string $a_doc_id
     * @return void
     */
    public static function deleteByDocumentId(string $a_doc_id): void
    {
        foreach (self::getInstancesByDocumentId($a_doc_id) as $ass) {
            $ass->delete();
        }
    }

    /**
     * Get activities for user
     * @param int $a_user_id
     * @param int $a_ts_from
     * @param int|null $a_ts_to
     * @return self[]
     */
    public static function getActivitiesForUser(int $a_user_id, int $a_ts_from, ?int $a_ts_to = null): array
    {
        global $DIC;
        $db = $DIC->database();

        $a_ts_to = $a_ts_to == null ? time() : $a_ts_to;

        $set = $db->queryF(
            "SELECT * FROM " . ilNolejPlugin::TABLE_ACTIVITY
            . " WHERE user_id = %s"
            . " AND tstamp >= %s AND tstamp <= %s"
            . " AND notified = 'n'"
            . " AND (document_id, tstamp) IN ("
            . "   SELECT document_id, MAX(tstamp) FROM " . ilNolejPlugin::TABLE_ACTIVITY
            . "   WHERE user_id = %s"
            . "   AND tstamp >= %s AND tstamp <= %s"
            . "   AND notified = 'n'"
            . "   GROUP BY user_id, document_id"
            . " )"
            . " ORDER BY tstamp DESC",
            ["integer", "integer", "integer", "integer", "integer", "integer"],
            [$a_user_id, $a_ts_from, $a_ts_to, $a_user_id, $a_ts_from, $a_ts_to]
        );
        $res = [];
        while ($rec = $db->fetchAssoc($set)) {
            $obj = new self();
            $obj->importDBRow($rec);
            $res[] = $obj;
        }
        return $res;
    }

    /**
     * @return int|null
     */
    public function lookupObjId(): ?int
    {
        global $DIC;

        if ($this->objId != null) {
            return $this->objId;
        }

        $db = $DIC->database();

        $res = $db->queryF(
            "SELECT id FROM " . ilNolejPlugin::TABLE_DATA
            . " WHERE document_id = %s",
            ["text"],
            [$this->getDocumentId()]
        );
        $row = $db->fetchAssoc($res);
        $this->objId = is_array($row) ? (int) $row["id"] : 0;
        return $this->objId;
    }

    /**
     * @return string|null
     */
    public function lookupDocumentTitle(): ?string
    {
        $objId = $this->lookupObjId();
        return $objId == null ? null : ilObject::_lookupTitle($objId);
    }

    /**
     * @return int|null
     */
    public function lookupRefId(): ?int
    {
        if ($this->refId != null) {
            return $this->refId;
        }
        $objId = $this->lookupObjId();
        if ($objId == null) {
            return null;
        }
        $refs = ilObject::_getAllReferences($objId);
        if (count($refs) > 0) {
            return (int) array_values($refs)[0];
        }
        return null;
    }
}
