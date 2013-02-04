<?
include(SERVER_ROOT.'/sections/requests/functions.php'); // get_request_tags()

function get_group_info($GroupID, $Return = true, $RevisionID = 0, $PersonalProperties = true) {
	global $Cache, $DB;
	if (!$RevisionID) {
		$TorrentCache = $Cache->get_value('torrents_details_'.$GroupID);

		// This block can be used to test if the cached data predates structure changes
		if (isset($TorrentCache[0][0])) {
			$OutdatedCache = true;
		} else {
			$Torrent = current($TorrentCache[1]);
			if (!isset($Torrent['InfoHash'])) {
				$OutdatedCache = true;
			}
		}
	}
	if ($RevisionID || !is_array($TorrentCache) || isset($OutdatedCache)) {
		// Fetch the group details

		$SQL = "SELECT ";

		if (!$RevisionID) {
			$SQL .= "
				g.WikiBody,
				g.WikiImage, ";
		} else {
			$SQL .= "
				w.Body,
				w.Image, ";
		}

		$SQL .= "
			g.ID,
			g.Name,
			g.Year,
			g.RecordLabel,
			g.CatalogueNumber,
			g.ReleaseType,
			g.CategoryID,
			g.Time,
			g.VanityHouse,
			GROUP_CONCAT(DISTINCT tags.Name SEPARATOR '|'),
			GROUP_CONCAT(DISTINCT tags.ID SEPARATOR '|'),
			GROUP_CONCAT(tt.UserID SEPARATOR '|'),
			GROUP_CONCAT(tt.PositiveVotes SEPARATOR '|'),
			GROUP_CONCAT(tt.NegativeVotes SEPARATOR '|')
			FROM torrents_group AS g
			LEFT JOIN torrents_tags AS tt ON tt.GroupID=g.ID
			LEFT JOIN tags ON tags.ID=tt.TagID";

		if ($RevisionID) {
			$SQL .= "
				LEFT JOIN wiki_torrents AS w ON w.PageID='".db_string($GroupID)."' AND w.RevisionID='".db_string($RevisionID)."' ";
		}

		$SQL .= "
			WHERE g.ID='".db_string($GroupID)."'
			GROUP BY NULL";

		$DB->query($SQL);
		$TorrentDetails = $DB->next_record(MYSQLI_ASSOC);

		// Fetch the individual torrents

		$DB->query("
			SELECT
			t.ID,
			t.Media,
			t.Format,
			t.Encoding,
			t.Remastered,
			t.RemasterYear,
			t.RemasterTitle,
			t.RemasterRecordLabel,
			t.RemasterCatalogueNumber,
			t.Scene,
			t.HasLog,
			t.HasCue,
			t.LogScore,
			t.FileCount,
			t.Size,
			t.Seeders,
			t.Leechers,
			t.Snatched,
			t.FreeTorrent,
			t.Time,
			t.Description,
			t.FileList,
			t.FilePath,
			t.UserID,
			t.last_action,
			HEX(t.info_hash) AS InfoHash,
			tbt.TorrentID AS BadTags,
			tbf.TorrentID AS BadFolders,
			tfi.TorrentID AS BadFiles,
			ca.TorrentID AS CassetteApproved,
			lma.TorrentID AS LossymasterApproved,
			lwa.TorrentID AS LossywebApproved,
			t.LastReseedRequest,
			tln.TorrentID AS LogInDB,
			t.ID AS HasFile
			FROM torrents AS t
			LEFT JOIN torrents_bad_tags AS tbt ON tbt.TorrentID=t.ID
			LEFT JOIN torrents_bad_folders AS tbf on tbf.TorrentID=t.ID
			LEFT JOIN torrents_bad_files AS tfi on tfi.TorrentID=t.ID
			LEFT JOIN torrents_cassette_approved AS ca on ca.TorrentID=t.ID
			LEFT JOIN torrents_lossymaster_approved AS lma on lma.TorrentID=t.ID
			LEFT JOIN torrents_lossyweb_approved AS lwa on lwa.TorrentID=t.ID
			LEFT JOIN torrents_logs_new AS tln ON tln.TorrentID=t.ID
			WHERE t.GroupID='".db_string($GroupID)."'
			GROUP BY t.ID
			ORDER BY t.Remastered ASC, (t.RemasterYear <> 0) DESC, t.RemasterYear ASC, t.RemasterTitle ASC, t.RemasterRecordLabel ASC, t.RemasterCatalogueNumber ASC, t.Media ASC, t.Format, t.Encoding, t.ID");

		$TorrentList = $DB->to_array('ID', MYSQLI_ASSOC);
		if (count($TorrentList) == 0) {
			header("Location: log.php?search=".(empty($_GET['torrentid']) ? "Group+$GroupID" : "Torrent+$_GET[torrentid]"));
			die();
		}
		if (in_array(0, $DB->collect('Seeders'))) {
			$CacheTime = 600;
		} else {
			$CacheTime = 3600;
		}
		// Store it all in cache
		if (!$RevisionID) {
			$Cache->cache_value('torrents_details_'.$GroupID, array($TorrentDetails, $TorrentList), $CacheTime);
		}
	} else { // If we're reading from cache
		$TorrentDetails = $TorrentCache[0];
		$TorrentList = $TorrentCache[1];
	}

	if ($PersonalProperties) {
		// Fetch all user specific torrent and group properties
		$TorrentDetails['Flags'] = array('IsSnatched' => false);
		foreach ($TorrentList as &$Torrent) {
			Torrents::torrent_properties($Torrent, $TorrentDetails['Flags']);
		}
	}

	if ($Return) {
		return array($TorrentDetails, $TorrentList);
	}
}

//Check if a givin string can be validated as a torrenthash
function is_valid_torrenthash($Str) {
	//6C19FF4C 6C1DD265 3B25832C 0F6228B2 52D743D5
	$Str = str_replace(' ', '', $Str);
	if(preg_match('/^[0-9a-fA-F]{40}$/', $Str))
		return $Str;
	return false;
}


//After adjusting / deleting logs, recalculate the score for the torrent.
function set_torrent_logscore($TorrentID) {
	global $DB;
	$DB->query("UPDATE torrents SET LogScore = (SELECT FLOOR(AVG(Score)) FROM torrents_logs_new WHERE TorrentID = ".$TorrentID.") WHERE ID = ".$TorrentID);
}

function get_group_requests($GroupID) {
	if (empty($GroupID) || !is_number($GroupID)) {
		return array();
	}
	global $DB, $Cache;
	
	$Requests = $Cache->get_value('requests_group_'.$GroupID);
	if ($Requests === FALSE) {
		$DB->query("SELECT ID FROM requests WHERE GroupID = $GroupID AND TimeFilled = '0000-00-00 00:00:00'");
		$Requests = $DB->collect('ID');
		$Cache->cache_value('requests_group_'.$GroupID, $Requests, 0);
	}
	$Requests = Requests::get_requests($Requests);
	return $Requests['matches'];
}
