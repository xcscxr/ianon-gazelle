<?php

View::show_header('Staff PMs', 'staffpm');

// Get messages
$StaffPMs = $DB->prepared_query("
    SELECT ID,
        Subject,
        UserID,
        Status,
        Level,
        AssignedToUser,
        Date,
        Unread
    FROM staff_pm_conversations
    WHERE UserID = ?
    ORDER BY Status, Date DESC
    ", $LoggedUser['ID']
);

// Start page
?>
<div class="thin">
    <div class="header">
        <h2>Staff PMs</h2>
        <div class="linkbox">
            <a href="#" onclick="$('#compose').gtoggle();" class="brackets">Compose new</a>
        </div>
    </div>
    <br />
    <br />
    <?php View::parse('generic/reply/staffpm.php', ['Hidden' => true]); ?>
    <div class="box pad" id="inbox">
<?php

if (!$DB->has_results()) {
    // No messages
?>
        <h2>No messages</h2>
<?php
} else {
    // Messages, draw table
?>
        <form class="manage_form" name="staff_messages" method="post" action="staffpm.php" id="messageform">
            <input type="hidden" name="action" value="multiresolve" />
            <h3>Open messages</h3>
            <table class="message_table checkboxes">
                <tr class="colhead">
                    <td width="10"><input type="checkbox" onclick="toggleChecks('messageform', this);" /></td>
                    <td width="50%">Subject</td>
                    <td>Date</td>
                    <td>Assigned to</td>
                </tr>
<?php
    // List messages
    $Row = 'a';
    $ShowBox = 1;
    while ([$ID, $Subject, $UserID, $Status, $Level, $AssignedToUser, $Date, $Unread] = $DB->next_record()) {
        if ($Unread === '1') {
            $RowClass = 'unreadpm';
        } else {
            $Row = $Row === 'a' ? 'b' : 'a';
            $RowClass = "row$Row";
        }

        if ($Status == 'Resolved') {
            $ShowBox++;
        }
        if ($ShowBox == 2) {
            // First resolved PM
?>
            </table>
            <br />
            <h3>Resolved messages</h3>
            <table class="message_table checkboxes">
                <tr class="colhead">
                    <td width="10"><input type="checkbox" onclick="toggleChecks('messageform',this)" /></td>
                    <td width="50%">Subject</td>
                    <td>Date</td>
                    <td>Assigned to</td>
                </tr>
<?php
        }

        // Get assigned
        $Assigned = ($Level == 0) ? 'First Line Support' : $ClassLevels[$Level]['Name'];
        // No + on Sysops
        if ($Assigned != 'Sysop') {
            $Assigned .= '+';
        }

        // Table row
?>
                <tr class="<?=$RowClass?>">
                    <td class="center"><input type="checkbox" name="id[]" value="<?=$ID?>" /></td>
                    <td><a href="staffpm.php?action=viewconv&amp;id=<?=$ID?>"><?=display_str($Subject)?></a></td>
                    <td><?=time_diff($Date, 2, true)?></td>
                    <td><?=$Assigned?></td>
                </tr>
<?php
        $DB->set_query_id($StaffPMs);
    }

    // Close table and multiresolve form
?>
            </table>
            <div class="submit_div">
                <input type="submit" value="Resolve selected" />
            </div>
        </form>
<?php
}
?>
    </div>
</div>
<?php View::show_footer(); ?>
