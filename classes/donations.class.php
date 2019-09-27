<?php

define('BTC_API_URL', 'https://api.bitcoinaverage.com/ticker/global/EUR/');
define('USD_API_URL', 'http://www.google.com/ig/calculator?hl=en&q=1USD=?EUR');

class Donations {
    private static $ForumDescriptions = [
        "I want only two houses, rather than seven... I feel like letting go of things",
        "A billion here, a billion there, sooner or later it adds up to real money.",
        "I've cut back, because I'm buying a house in the West Village.",
        "Some girls are just born with glitter in their veins.",
        "I get half a million just to show up at parties. My life is, like, really, really fun.",
        "Some people change when they think they're a star or something",
        "I'd rather not talk about money. It’s kind of gross.",
        "I have not been to my house in Bermuda for two or three years, and the same goes for my house in Portofino. How long do I have to keep leading this life of sacrifice?",
        "When I see someone who is making anywhere from $300,000 to $750,000 a year, that's middle class.",
        "Money doesn't make you happy. I now have $50 million but I was just as happy when I had $48 million.",
        "I'd rather smoke crack than eat cheese from a tin.",
        "I am who I am. I can’t pretend to be somebody who makes $25,000 a year.",
        "A girl never knows when she might need a couple of diamonds at ten 'o' clock in the morning.",
        "I wouldn't run for president. I wouldn't want to move to a smaller house.",
        "I have the stardom glow.",
        "What's Walmart? Do they like, sell wall stuff?",
        "Whenever I watch TV and see those poor starving kids all over the world, I can't help but cry. I mean I'd love to be skinny like that, but not with all those flies and death and stuff.",
        "Too much money ain't enough money.",
        "What's a soup kitchen?",
        "I work very hard and I’m worth every cent!",
        "To all my Barbies out there who date Benjamin Franklin, George Washington, Abraham Lincoln, you'll be better off in life. Get that money."
    ];

    private static $IsSchedule = false;

    public static function regular_donate($UserID, $DonationAmount, $Source, $Reason, $Currency = "EUR") {
        self::donate($UserID, [
            "Reason" => $Reason,
            "Source" => $Source,
            "Price" => $DonationAmount,
            "Currency" => $Currency,
            "SendPM" => true
        ]);
    }

    public static function donate($UserID, array $Args) {
        $UserID = (int)$UserID;
        $QueryID = G::$DB->get_query_id();

        G::$DB->prepared_query('
            SELECT 1
            FROM users_main
            WHERE ID = ?
            ', $UserID
        );
        if (G::$DB->has_results()) {
            G::$Cache->InternalCache = false;

            // Legacy donor, should remove at some point
            G::$DB->prepared_query('
                UPDATE users_info
                SET Donor = ?
                WHERE UserID = ?
                ', '1', $UserID
            );
            // Give them an invite the first time they donate
            $FirstInvite = G::$DB->affected_rows();

            // A staff member is directly manipulating donor points
            if (isset($Args['Manipulation']) && $Args['Manipulation'] === "Direct") {
                $DonorPoints = $Args['Rank'];
                $AdjustedRank = $Args['Rank'] >= MAX_EXTRA_RANK ? MAX_EXTRA_RANK : $Args['Rank'];
                $ConvertedPrice = 0;
                G::$DB->prepared_query('
                    INSERT INTO users_donor_ranks
                           (UserID, Rank, TotalRank, DonationTime, RankExpirationTime)
                    VALUES (?,      ?,    ?,         now(),        now())
                    ON DUPLICATE KEY UPDATE
                        Rank = ?,
                        TotalRank = ?,
                        DonationTime = now(),
                        RankExpirationTime = now()
                    ', $UserID, $AdjustedRank, $Args['TotalRank'],
                        $AdjustedRank, $Args['TotalRank']
                );
            } else {
                // Donations from the store get donor points directly, no need to calculate them
                if (isset($Args['Source']) && $Args['Source'] == "Store Parser") {
                    $ConvertedPrice = self::currency_exchange($Args['Amount'] * $Args['Price'], $Args['Currency']);
                } else {
                    $ConvertedPrice = self::currency_exchange($Args['Price'], $Args['Currency']);
                    $DonorPoints = self::calculate_rank($ConvertedPrice);
                }
                $IncreaseRank = $DonorPoints;

                // Rank is the same thing as DonorPoints
                $CurrentRank = self::get_rank($UserID);
                // A user's donor rank can never exceed MAX_EXTRA_RANK
                // If the amount they donated causes it to overflow, chnage it to MAX_EXTRA_RANK
                // The total rank isn't affected by this, so their original donor point value is added to it
                if (($CurrentRank + $DonorPoints) >= MAX_EXTRA_RANK) {
                    $AdjustedRank = MAX_EXTRA_RANK;
                } else {
                    $AdjustedRank = $CurrentRank + $DonorPoints;
                }
                G::$DB->prepared_query('
                    INSERT INTO users_donor_ranks
                           (UserID, Rank, TotalRank, DonationTime, RankExpirationTime)
                    VALUES (?,      ?,    ?,         now(),        now())
                    ON DUPLICATE KEY UPDATE
                        Rank = ?,
                        TotalRank = TotalRank + ?,
                        DonationTime = now(),
                        RankExpirationTime = now()
                    ', $UserID, $AdjustedRank, $DonorPoints,
                        $AdjustedRank, $DonorPoints
                );
            }
            // Donor cache key is outdated
            G::$Cache->delete_value("donor_info_$UserID");

            // Get their rank
            $Rank = self::get_rank($UserID);
            $TotalRank = self::get_total_rank($UserID);

            // Now that their rank and total rank has been set, we can calculate their special rank
            self::calculate_special_rank($UserID);

            // Hand out invites
            G::$DB->prepared_query('
                SELECT InvitesReceivedRank
                FROM users_donor_ranks
                WHERE UserID = ?
                ', $UserID
            );
            list($InvitesReceivedRank) = G::$DB->next_record();
            $AdjustedRank = $Rank >= MAX_RANK ? (MAX_RANK - 1) : $Rank;
            $InviteRank = $AdjustedRank - $InvitesReceivedRank;
            if ($InviteRank > 0) {
                G::$DB->prepared_query('
                    UPDATE users_main
                    SET Invites = Invites + ?
                    WHERE ID = ?
                    ', $FirstInvite + $InviteRank, $UserID
                );
                G::$DB->prepared_query('
                    UPDATE users_donor_ranks
                    SET InvitesReceivedRank = ?
                    WHERE UserID = ?
                    ', $AdjustedRank, $UserID);
            }

            // Send them a thank you PM
            if ($Args['SendPM']) {
                Misc::send_pm(
                    $UserID,
                    0,
                    'Your contribution has been received and credited. Thank you!',
                    self::get_pm_body($Args['Source'], $Args['Currency'], $Args['Price'], $IncreaseRank, $Rank)
                );
            }

            // Lastly, add this donation to our history
            G::$DB->prepared_query('
                INSERT INTO donations
                       (UserID, Amount, Source, Reason, Currency, AddedBy, Rank, TotalRank, Time)
                VALUES (?,      ?,      ?,      ?,      ?,        ?,       ?,    ?,         now())
                ', $UserID, $ConvertedPrice, $Args['Source'], $Args['Reason'], $Args['Currency'],
                    self::$IsSchedule ? 0 : G::$LoggedUser['ID'], $DonorPoints, $TotalRank
            );

            // Clear their user cache keys because the users_info values has been modified
            G::$Cache->delete_value("user_info_$UserID");
            G::$Cache->delete_value("user_info_heavy_$UserID");
            G::$Cache->delete_value("donor_info_$UserID");

        }
        G::$DB->set_query_id($QueryID);
    }

    private static function calculate_special_rank($UserID) {
        $UserID = (int)$UserID;
        $QueryID = G::$DB->get_query_id();
        // Are they are special?
        G::$DB->prepared_query('
            SELECT TotalRank, SpecialRank
            FROM users_donor_ranks
            WHERE UserID = ?
            ', $UserID
        );
        if (G::$DB->has_results()) {
            // Adjust their special rank depending on the total rank.
            list($TotalRank, $SpecialRank) = G::$DB->next_record();
            if ($TotalRank < 10) {
                $SpecialRank = 0;
            }
            if ($SpecialRank < 1 && $TotalRank >= 10) {
                Misc::send_pm($UserID, 0, "You've Reached Special Donor Rank #1! You've Earned: One User Pick. Details Inside.", self::get_special_rank_one_pm());
                $SpecialRank = 1;
            }
            if ($SpecialRank < 2 && $TotalRank >= 20) {
                Misc::send_pm($UserID, 0, "You've Reached Special Donor Rank #2! You've Earned: The Double-Avatar. Details Inside.", self::get_special_rank_two_pm());
                $SpecialRank = 2;
            }
            if ($SpecialRank < 3 && $TotalRank >= 50) {
                Misc::send_pm($UserID, 0, "You've Reached Special Donor Rank #3! You've Earned: Diamond Rank. Details Inside.", self::get_special_rank_three_pm());
                $SpecialRank = 3;
            }
            // Make them special
            G::$DB->prepared_query('
                UPDATE users_donor_ranks
                SET SpecialRank = ?
                WHERE UserID = ?
                ', $SpecialRank, $UserID
            );
            G::$Cache->delete_value("donor_info_$UserID");
        }
        G::$DB->set_query_id($QueryID);
    }

    public static function schedule() {
        self::$IsSchedule = true;

        DonationsBitcoin::find_new_donations();
        self::expire_ranks();
        self::get_new_conversion_rates();
    }

    public static function expire_ranks() {
        $QueryID = G::$DB->get_query_id();
        G::$DB->query("
            SELECT UserID, Rank
            FROM users_donor_ranks
            WHERE Rank > 1
                AND SpecialRank != 3
                AND RankExpirationTime < NOW() - INTERVAL 766 HOUR");
                // 2 hours less than 32 days to account for schedule run times

        if (G::$DB->record_count() > 0) {
            $UserIDs = [];
            while (list($UserID, $Rank) = G::$DB->next_record()) {
                G::$Cache->delete_value("donor_info_$UserID");
                G::$Cache->delete_value("donor_title_$UserID");
                G::$Cache->delete_value("donor_profile_rewards_$UserID");
                $UserIDs[] = $UserID;
            }
            $In = implode(',', $UserIDs);
            G::$DB->query("
                UPDATE users_donor_ranks
                SET Rank = Rank - IF(Rank = " . MAX_RANK . ", 2, 1), RankExpirationTime = NOW()
                WHERE UserID IN ($In)");
        }
        G::$DB->set_query_id($QueryID);
    }

    private static function calculate_rank($Amount) {
        return floor($Amount / 5);
    }

    public static function update_rank($UserID, $Rank, $TotalRank, $Reason) {
        $Rank = (int)$Rank;
        $TotalRank = (int)$TotalRank;

        self::donate($UserID, [
            "Reason" => $Reason,
            "Source" => "Modify Values",
            "Currency" => "EUR",
            "SendPM" => false,
            "Manipulation" => "Direct",
            "Rank" => $Rank,
            "TotalRank" => $TotalRank
        ]);
    }

    public static function hide_stats($UserID) {
        $QueryID = G::$DB->get_query_id();
        G::$DB->prepared_query('
            INSERT INTO users_donor_ranks
                   (UserID, Hidden)
            VALUES (?,      ?)
            ON DUPLICATE KEY UPDATE
                Hidden = ?
            ', $UserID, '1', '1'
        );
        G::$DB->set_query_id($QueryID);
    }

    public static function show_stats($UserID) {
        $QueryID = G::$DB->get_query_id();
        G::$DB->prepared_query('
            INSERT INTO users_donor_ranks
                   (UserID, Hidden)
            VALUES (?,      ?)
            ON DUPLICATE KEY UPDATE
                Hidden = ?
            ', $UserID, '0', '0'
        );
        G::$DB->set_query_id($QueryID);
    }

    public static function is_visible($UserID) {
        $QueryID = G::$DB->get_query_id();
        G::$DB->prepared_query('
            SELECT Hidden
            FROM users_donor_ranks
            WHERE UserID = ?
                AND Hidden = ?
            ', $UserID, '0'
        );
        $HasResults = G::$DB->has_results();
        G::$DB->set_query_id($QueryID);
        return $HasResults;
    }

    public static function has_donor_forum($UserID) {
        return self::get_rank($UserID) >= DONOR_FORUM_RANK || self::get_special_rank($UserID) >= MAX_SPECIAL_RANK;
    }

    /**
     * Put all the common donor info in the same cache key to save some cache calls
     */
    public static function get_donor_info($UserID) {
        // Our cache class should prevent identical memcached requests
        $DonorInfo = G::$Cache->get_value("donor_info_$UserID");
        if ($DonorInfo === false) {
            $QueryID = G::$DB->get_query_id();
            G::$DB->prepared_query('
                SELECT
                    Rank,
                    SpecialRank,
                    TotalRank,
                    DonationTime,
                    RankExpirationTime + INTERVAL 766 HOUR
                FROM users_donor_ranks
                WHERE UserID = ?
                ', $UserID
            );
                // 2 hours less than 32 days to account for schedule run times
            if (G::$DB->has_results()) {
                list($Rank, $SpecialRank, $TotalRank, $DonationTime, $ExpireTime) = G::$DB->next_record(MYSQLI_NUM, false);
                if ($DonationTime === null) {
                    $DonationTime = 0;
                }
                if ($ExpireTime === null) {
                    $ExpireTime = 0;
                }
            } else {
                $Rank = $SpecialRank = $TotalRank = $DonationTime = $ExpireTime = 0;
            }
            if (Permissions::is_mod($UserID)) {
                $Rank = MAX_EXTRA_RANK;
                $SpecialRank = MAX_SPECIAL_RANK;
            }
            G::$DB->prepared_query('
                SELECT
                    IconMouseOverText,
                    AvatarMouseOverText,
                    CustomIcon,
                    CustomIconLink,
                    SecondAvatar
                FROM donor_rewards
                WHERE UserID = ?
                ', $UserID
            );
            $Rewards = G::$DB->next_record(MYSQLI_ASSOC);
            G::$DB->set_query_id($QueryID);

            $DonorInfo = [
                'Rank' => (int)$Rank,
                'SRank' => (int)$SpecialRank,
                'TotRank' => (int)$TotalRank,
                'Time' => $DonationTime,
                'ExpireTime' => $ExpireTime,
                'Rewards' => $Rewards
            ];
            G::$Cache->cache_value("donor_info_$UserID", $DonorInfo, 0);
        }
        return $DonorInfo;
    }

    public static function get_rank($UserID) {
        return self::get_donor_info($UserID)['Rank'];
    }

    public static function get_special_rank($UserID) {
        return self::get_donor_info($UserID)['SRank'];
    }

    public static function get_total_rank($UserID) {
        return self::get_donor_info($UserID)['TotRank'];
    }

    public static function get_donation_time($UserID) {
        return self::get_donor_info($UserID)['Time'];
    }

    public static function get_personal_collages($UserID) {
        $DonorInfo = self::get_donor_info($UserID);
        if ($DonorInfo['SRank'] == MAX_SPECIAL_RANK) {
            $Collages = 5;
        } else {
            $Collages = min($DonorInfo['Rank'], 5); // One extra collage per donor rank up to 5
        }
        return $Collages;
    }

    public static function get_titles($UserID) {
        $Results = G::$Cache->get_value("donor_title_$UserID");
        if ($Results === false) {
            $QueryID = G::$DB->get_query_id();
            G::$DB->prepared_query('
                SELECT Prefix, Suffix, UseComma
                FROM donor_forum_usernames
                WHERE UserID = ?
                ', $UserID
            );
            $Results = G::$DB->next_record();
            G::$DB->set_query_id($QueryID);
            G::$Cache->cache_value("donor_title_$UserID", $Results, 0);
        }
        return $Results;
    }



    public static function get_enabled_rewards($UserID) {
        $Rewards = [];
        $Rank = self::get_rank($UserID);
        $SpecialRank = self::get_special_rank($UserID);
        $HasAll = $SpecialRank == 3;

        $Rewards = [
            'HasAvatarMouseOverText' => false,
            'HasCustomDonorIcon' => false,
            'HasDonorForum' => false,
            'HasDonorIconLink' => false,
            'HasDonorIconMouseOverText' => false,
            'HasProfileInfo1' => false,
            'HasProfileInfo2' => false,
            'HasProfileInfo3' => false,
            'HasProfileInfo4' => false,
            'HasSecondAvatar' => false
        ];

        if ($Rank >= 2 || $HasAll) {
            $Rewards["HasDonorIconMouseOverText"] = true;
            $Rewards["HasProfileInfo1"] = true;
        }
        if ($Rank >= 3 || $HasAll) {
            $Rewards["HasAvatarMouseOverText"] = true;
            $Rewards["HasProfileInfo2"] = true;
        }
        if ($Rank >= 4 || $HasAll) {
            $Rewards["HasDonorIconLink"] = true;
            $Rewards["HasProfileInfo3"] = true;
        }
        if ($Rank >= MAX_RANK || $HasAll) {
            $Rewards["HasCustomDonorIcon"] = true;
            $Rewards["HasDonorForum"] = true;
            $Rewards["HasProfileInfo4"] = true;
        }
        if ($SpecialRank >= 2) {
            $Rewards["HasSecondAvatar"] = true;
        }
        return $Rewards;
    }

    public static function get_rewards($UserID) {
        return self::get_donor_info($UserID)['Rewards'];
    }

    public static function get_profile_rewards($UserID) {
        $Results = G::$Cache->get_value("donor_profile_rewards_$UserID");
        if ($Results === false) {
            $QueryID = G::$DB->get_query_id();
            G::$DB->prepared_query('
                SELECT
                    ProfileInfo1,
                    ProfileInfoTitle1,
                    ProfileInfo2,
                    ProfileInfoTitle2,
                    ProfileInfo3,
                    ProfileInfoTitle3,
                    ProfileInfo4,
                    ProfileInfoTitle4
                FROM donor_rewards
                WHERE UserID = ?
                ', $UserID
            );
            $Results = G::$DB->next_record();
            G::$DB->set_query_id($QueryID);
            G::$Cache->cache_value("donor_profile_rewards_$UserID", $Results, 0);
        }
        return $Results;
    }

    private static function add_profile_info_reward($Counter, &$Insert, &$Values, &$Update) {
        if (isset($_POST["profile_title_" . $Counter]) && isset($_POST["profile_info_" . $Counter])) {
            $ProfileTitle = db_string($_POST["profile_title_" . $Counter]);
            $ProfileInfo = db_string($_POST["profile_info_" . $Counter]);
            $ProfileInfoTitleSQL = "ProfileInfoTitle" . $Counter;
            $ProfileInfoSQL = "ProfileInfo" . $Counter;
            $Insert[] = "$ProfileInfoTitleSQL";
            $Values[] = "'$ProfileInfoTitle'";
            $Update[] = "$ProfileInfoTitleSQL = '$ProfileTitle'";
            $Insert[] = "$ProfileInfoSQL";
            $Values[] = "'$ProfileInfo'";
            $Update[] = "$ProfileInfoSQL = '$ProfileInfo'";
        }
    }



    public static function update_rewards($UserID) {
        $Rank = self::get_rank($UserID);
        $SpecialRank = self::get_special_rank($UserID);
        $HasAll = $SpecialRank == 3;
        $Counter = 0;
        $Insert = [];
        $Values = [];
        $Update = [];

        $Insert[] = "UserID";
        $Values[] = "'$UserID'";
        if ($Rank >= 1 || $HasAll) {

        }
        if ($Rank >= 2 || $HasAll) {
            if (isset($_POST['donor_icon_mouse_over_text'])) {
                $IconMouseOverText = db_string($_POST['donor_icon_mouse_over_text']);
                $Insert[] = "IconMouseOverText";
                $Values[] = "'$IconMouseOverText'";
                $Update[] = "IconMouseOverText = '$IconMouseOverText'";
            }
            $Counter++;
        }
        if ($Rank >= 3 || $HasAll) {
            if (isset($_POST['avatar_mouse_over_text'])) {
                $AvatarMouseOverText = db_string($_POST['avatar_mouse_over_text']);
                $Insert[] = "AvatarMouseOverText";
                $Values[] = "'$AvatarMouseOverText'";
                $Update[] = "AvatarMouseOverText = '$AvatarMouseOverText'";
            }
            $Counter++;
        }
        if ($Rank >= 4 || $HasAll) {
            if (isset($_POST['donor_icon_link'])) {
                $CustomIconLink = db_string($_POST['donor_icon_link']);
                if (!Misc::is_valid_url($CustomIconLink)) {
                    $CustomIconLink = '';
                }
                $Insert[] = "CustomIconLink";
                $Values[] = "'$CustomIconLink'";
                $Update[] = "CustomIconLink = '$CustomIconLink'";
            }
            $Counter++;
        }
        if ($Rank >= MAX_RANK || $HasAll) {
            if (isset($_POST['donor_icon_custom_url'])) {
                $CustomIcon = db_string($_POST['donor_icon_custom_url']);
                if (!Misc::is_valid_url($CustomIcon)) {
                    $CustomIcon = '';
                }
                $Insert[] = "CustomIcon";
                $Values[] = "'$CustomIcon'";
                $Update[] = "CustomIcon = '$CustomIcon'";
            }
            self::update_titles($UserID, $_POST['donor_title_prefix'], $_POST['donor_title_suffix'], $_POST['donor_title_comma']);
            $Counter++;
        }
        for ($i = 1; $i <= $Counter; $i++) {
            self::add_profile_info_reward($i, $Insert, $Values, $Update);
        }
        if ($SpecialRank >= 2) {
            if (isset($_POST['second_avatar'])) {
                $SecondAvatar = db_string($_POST['second_avatar']);
                if (!Misc::is_valid_url($SecondAvatar)) {
                    $SecondAvatar = '';
                }
                $Insert[] = "SecondAvatar";
                $Values[] = "'$SecondAvatar'";
                $Update[] = "SecondAvatar = '$SecondAvatar'";
            }
        }
        $Insert = implode(', ', $Insert);
        $Values = implode(', ', $Values);
        $Update = implode(', ', $Update);
        if ($Counter > 0) {
            $QueryID = G::$DB->get_query_id();
            G::$DB->query("
                INSERT INTO donor_rewards
                    ($Insert)
                VALUES
                    ($Values)
                ON DUPLICATE KEY UPDATE
                    $Update");
            G::$DB->set_query_id($QueryID);
        }
        G::$Cache->delete_value("donor_profile_rewards_$UserID");
        G::$Cache->delete_value("donor_info_$UserID");

    }

    public static function update_titles($UserID, $Prefix, $Suffix, $UseComma) {
        $QueryID = G::$DB->get_query_id();
        $Prefix = trim($Prefix);
        $Suffix = trim($Suffix);
        $UseComma = empty($UseComma) ? true : false;
        G::$DB->prepared_query('
            INSERT INTO donor_forum_usernames
                   (UserID, Prefix, Suffix, UseComma)
            VALUES (?,      ?,      ?,      ?)
            ON DUPLICATE KEY UPDATE
                Prefix = ?, Suffix = ?, UseComma = ?
            ', $UserID, $Prefix, $Suffix, $UseComma,
                $Prefix, $Suffix, $UseComma
        );
        G::$Cache->delete_value("donor_title_$UserID");
        G::$DB->set_query_id($QueryID);
    }

    public static function get_donation_history($UserID) {
        $UserID = (int)$UserID;
        if (empty($UserID)) {
            error(404);
        }
        $QueryID = G::$DB->get_query_id();
        G::$DB->prepared_query('
            SELECT Amount, Email, Time, Currency, Reason, Source, AddedBy, Rank, TotalRank
            FROM donations
            WHERE UserID = ?
            ORDER BY Time DESC
            ', $UserID
        );
        $DonationHistory = G::$DB->to_array(false, MYSQLI_ASSOC, false);
        G::$DB->set_query_id($QueryID);
        return $DonationHistory;
    }

    public static function get_rank_expiration($UserID) {
        $DonorInfo = self::get_donor_info($UserID);
        if ($DonorInfo['SRank'] == MAX_SPECIAL_RANK || $DonorInfo['Rank'] == 1) {
            $Return = 'Never';
        } elseif ($DonorInfo['ExpireTime']) {
            $ExpireTime = strtotime($DonorInfo['ExpireTime']);
            if ($ExpireTime - time() < 60) {
                $Return = 'Soon';
            } else {
                $Expiration = time_diff($ExpireTime); // 32 days
                $Return = "in $Expiration";
            }
        } else {
            $Return = '';
        }
        return $Return;
    }

    public static function get_leaderboard_position($UserID) {
        $UserID = (int)$UserID;
        $QueryID = G::$DB->get_query_id();
        G::$DB->query("SET @RowNum := 0");
        G::$DB->query("
            SELECT Position
            FROM (
                SELECT d.UserID, @RowNum := @RowNum + 1 AS Position
                FROM users_donor_ranks AS d
                ORDER BY TotalRank DESC
            ) l
            WHERE UserID = '$UserID'");
        if (G::$DB->has_results()) {
            list($Position) = G::$DB->next_record();
        } else {
            $Position = 0;
        }
        G::$DB->set_query_id($QueryID);
        return $Position;
    }

    public static function is_donor($UserID) {
        return self::get_rank($UserID) > 0;
    }

    public static function currency_exchange($Amount, $Currency) {
        if (!self::is_valid_currency($Currency)) {
            error("$Currency is not valid currency");
        }
        switch ($Currency) {
            case 'USD':
                $Amount = self::usd_to_euro($Amount);
                break;
            case 'BTC':
                $Amount = self::btc_to_euro($Amount);
                break;
            default:
                break;
        }
        return round($Amount, 2);
    }

    public static function is_valid_currency($Currency) {
        return $Currency == 'EUR' || $Currency == 'BTC' || $Currency == 'USD';
    }

    public static function btc_to_euro($Amount) {
        $Rate = G::$Cache->get_value('btc_rate');
        if (empty($Rate)) {
            $Rate = self::get_stored_conversion_rate('BTC');
            G::$Cache->cache_value('btc_rate', $Rate, 86400);
        }
        return $Rate * $Amount;
    }

    public static function usd_to_euro($Amount) {
        $Rate = G::$Cache->get_value('usd_rate');
        if (empty($Rate)) {
            $Rate = self::get_stored_conversion_rate('USD');
            G::$Cache->cache_value('usd_rate', $Rate, 86400);
        }
        return $Rate * $Amount;
    }

    public static function get_stored_conversion_rate($Currency) {
        $QueryID = G::$DB->get_query_id();
        G::$DB->prepared_query('
            SELECT Rate
            FROM currency_conversion_rates
            WHERE Currency = ?
            ', $Currency
        );
        list($Rate) = G::$DB->next_record(MYSQLI_NUM, false);
        G::$DB->set_query_id($QueryID);
        return $Rate;
    }

    private static function set_stored_conversion_rate($Currency, $Rate) {
        $QueryID = G::$DB->get_query_id();
        G::$DB->prepared_query('
            REPLACE INTO currency_conversion_rates
                   (Currency, Rate, Time)
            VALUES (?,        ?,    now())
            ', $Currency, $Rate
        );
        if ($Currency == 'USD') {
            $KeyName = 'usd_rate';
        } elseif ($Currency == 'BTC') {
            $KeyName = 'btc_rate';
        }
        G::$Cache->cache_value($KeyName, $Rate, 86400);
        G::$DB->set_query_id($QueryID);
    }

    private static function get_new_conversion_rates() {
        if ($BTC = file_get_contents(BTC_API_URL)) {
            $BTC = json_decode($BTC, true);
            if (isset($BTC['24h_avg'])) {
                if ($Rate = round($BTC['24h_avg'], 4)) { // We don't need good precision
                    self::set_stored_conversion_rate('BTC', $Rate);
                }
            }
        }
        if ($USD = file_get_contents(USD_API_URL)) {
            // Valid JSON isn't returned so we make it valid.
            $Replace = [
                'lhs' => '"lhs"',
                'rhs' => '"rhs"',
                'error' => '"error"',
                'icc' => '"icc"'
            ];

            $USD = str_replace(array_keys($Replace), array_values($Replace), $USD);
            $USD = json_decode($USD, true);
            if (isset($USD['rhs'])) {
                // The response is in format "# Euroes", extracts the numbers.
                $Rate = preg_split("/[\s,]+/", $USD['rhs']);
                if ($Rate = round($Rate[0], 4)) { // We don't need good precision
                    self::set_stored_conversion_rate('USD', $Rate);
                }
            }
        }
    }

    public static function get_forum_description() {
        return self::$ForumDescriptions[rand(0, count(self::$ForumDescriptions) - 1)];
    }

    private static function get_pm_body($Source, $Currency, $DonationAmount, $ReceivedRank, $CurrentRank) {
        if ($Currency != 'BTC') {
            $DonationAmount = number_format($DonationAmount, 2);
        }
        if ($CurrentRank >= MAX_RANK) {
            $CurrentRank = MAX_RANK - 1;
        } elseif ($CurrentRank == 5) {
            $CurrentRank = 4;
        }
        return "Thank you for your generosity and support. It's users like you who make all of this possible. What follows is a brief description of your transaction:
[*][b]You Contributed:[/b] $DonationAmount $Currency
[*][b]You Received:[/b] $ReceivedRank Donor Point".($ReceivedRank == 1 ? '' : 's')."
[*][b]Your Donor Rank:[/b] Donor Rank # $CurrentRank
Once again, thank you for your continued support of the site.

Sincerely,

".SITE_NAME.' Staff

[align=center][If you have any questions or concerns, please [url='.site_url().'staffpm.php]send a Staff PM[/url].]';
    }

    private static function get_special_rank_one_pm() {
        return 'Congratulations on reaching [url='.site_url().'forums.php?action=viewthread&threadid=178640&postid=4839790#post4839790]Special Rank #1[/url]! You\'ve been awarded [b]one user pick[/b]! This user pick will be featured on the '.SITE_NAME.' front page during an upcoming event. After you submit your pick, there is no guarantee as to how long it will take before your pick is featured. Picks will be featured on a first-submitted, first-served basis. Please abide by the following guidelines when making your selection:

[*]Pick something that hasn\'t been chosen. You can tell if a pick has been used previously by looking at the collages it\'s in.
[*]Complete the enclosed form carefully and completely.
[*]Send a [url='.site_url().'staffpm.php]Staff PM[/url] to request further information about the formatting of your pick, and the time at which it will be posted.

Sincerely,
'.SITE_NAME.' Staff';
       }

       private static function get_special_rank_two_pm() {
               return 'Congratulations on reaching [url='.site_url().'forums.php?action=viewthread&threadid=178640&postid=4839790#post4839790]Special Rank #2[/url]! You\'ve been awarded [b]double avatar functionality[/b]! To set a second avatar, please enter a URL leading to a valid image in the new field which has been unlocked in your [b]Personal Settings[/b]. Any avatar you choose must abide by normal avatar rules. When running your cursor over your avatar, it will flip to the alternate choice you\'ve established. Other users will also be able to view both of your avatars using this method.

At this time, we\'d like to thank you for your continued support of the site. The fact that you\'ve reached this milestone is testament to your belief in '.SITE_NAME.' as a project. It\'s dedicated users like you that keep us alive. Have fun with the new toy.

Sincerely,
'.SITE_NAME.' Staff';
       }

       private static function get_special_rank_three_pm() {
               return 'Congratulations on reaching [url='.site_url().'forums.php?action=viewthread&threadid=178640&postid=4839790#post4839790]Special Rank #3[/url]! You\'ve been awarded [b]Diamond Rank[/b]! Diamond Rank grants you the benefits associated with every Donor Rank up to and including Gold ([url='.site_url().'forums.php?action=viewthread&threadid=178640&postid=4839789#post4839789]Donor Rank #5[/url]). But unlike Donor Rank #5 - because Diamond Rank is a Special Rank - it will never expire.

At this time, we\'d like to thank you for your continued support of the site. The fact that you\'ve reached this milestone is testament to your belief in '.SITE_NAME.' as a project. It\'s dedicated users like you that keep us alive. Consider yourself one of our top supporters!

Sincerely,
'.SITE_NAME.' Staff';
    }
}
