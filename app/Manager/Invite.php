<?php

namespace Gazelle\Manager;

class Invite extends \Gazelle\Base {

    protected $search;

    public function findUserByKey(string $inviteKey, User $manager): ?\Gazelle\User {
        return $manager->findById(
            (int)self::$db->scalar("
                SELECT InviterID FROM invites WHERE InviteKey = ?
                ", $inviteKey
            )
        );
    }

    /**
     * Set a text filter on email addresses
     */
    public function setSearch(string $search) {
        $this->search = $search;
        return $this;
    }

    public function create(\Gazelle\User $user, string $email, string $reason, string $source): ?\Gazelle\Invite {
        self::$db->begin_transaction();
        $inviteKey = randomString();
        self::$db->prepared_query("
            INSERT INTO invites
                   (InviterID, InviteKey, Email, Reason, Expires)
            VALUES (?,         ?,         ?,     ?,      now() + INTERVAL 3 DAY)
            ", $user->id(), $inviteKey, $email, trim($_POST['reason'] ?? '')
        );
        $invite = new \Gazelle\Invite($inviteKey);
        $user->decrementInviteCount();
        if (preg_match('/^s-(\d+)$/', $source, $match)) {
            (new \Gazelle\Manager\InviteSource)->createPendingInviteSource($match[1], $inviteKey);
        }
        self::$db->commit();
        return $invite;
    }

    /**
     * How many pending invites are in circulation?
     *
     * @return int number of invites
     */
    public function totalPending(): int {
        return self::$db->scalar("
            SELECT count(*) FROM invites WHERE Expires > now()
        ");
    }

    public function emailExists(\Gazelle\User $user, string $email): bool {
        return (bool)self::$db->scalar("
            SELECT 1
            FROM invites
            WHERE InviterID = ?
                AND Email = ?
            ", $user->id(), $email
        );
    }

    public function inviteExists(string $key): bool {
        return (bool)self::$db->scalar("
            SELECT 1 FROM invites WHERE InviteKey = ?
            ", $key
        );
    }

    /**
     * Get a page of pending invites
     *
     * @return array list of pending invites [inviter_id, ipaddr, invite_key, expires, email]
     */
    public function pendingInvites(int $limit, int $offset): array {
        if (is_null($this->search)) {
            $where = "/* no email filter */";
            $args = [];
        } else {
            $where = "WHERE i.Email REGEXP ?";
            $args = [$this->search];
        }

        self::$db->prepared_query("
            SELECT i.InviterID AS user_id,
                um.IP AS ipaddr,
                i.InviteKey AS `key`,
                i.Expires AS expires,
                i.Email AS email
            FROM invites AS i
            INNER JOIN users_main AS um ON (um.ID = i.InviterID)
            $where
            ORDER BY i.Expires DESC
            LIMIT ? OFFSET ?
            ", ...array_merge($args, [$limit, $offset])
        );
        return self::$db->to_array(false, MYSQLI_ASSOC, false);
    }

    /**
     * Remove an invite
     *
     * @return bool true if something was actually removed
     */
    public function removeInviteKey(string $key): bool {
        self::$db->prepared_query("
            DELETE FROM invites
            WHERE InviteKey = ?
            ", trim($key)
        );
        return self::$db->affected_rows() !== 0;
    }

    /**
     * Expire unused invitations
     */
    public function expire(\Gazelle\Schedule\Task $task = null): int {
        self::$db->begin_transaction();
        self::$db->prepared_query("SELECT InviterID FROM invites WHERE Expires < now()");
        $list = self::$db->collect('InviterID', false);

        self::$db->prepared_query("DELETE FROM invites WHERE Expires < now()");
        self::$db->prepared_query("
            DELETE isp FROM invite_source_pending isp
            LEFT JOIN invites i ON (i.InviteKey = isp.invite_key)
            WHERE i.InviteKey IS NULL
        ");

        $expired = 0;
        foreach ($list as $userId) {
            self::$db->prepared_query("UPDATE users_main SET Invites = Invites + 1 WHERE ID = ?", $userId);
            self::$cache->delete_value("u_$userId");
            $task?->debug("Expired invite from user $userId", $userId);
            $expired++;
        }
        self::$db->commit();
        return $expired;
    }
}
