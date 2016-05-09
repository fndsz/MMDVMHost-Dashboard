<?php
function getLog() {
	// Open Logfile and copy loglines into LogLines-Array()
	$logLines = array();
	if ($log = fopen(MMDVMLOGFILE,'r')) {
		while ($logLine = fgets($log)) {
			array_push($logLines, $logLine);
		}
		fclose($log);
	}
	return $logLines;
}

// 00000000001111111111222222222233333333334444444444555555555566666666667777777777888888888899999999990000000000111111111122
// 01234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901
// M: 2016-04-29 00:15:00.013 D-Star, received network header from DG9VH   /ZEIT to CQCQCQ   via DCS002 S
// M: 2016-04-29 19:43:21.839 DMR Slot 2, received network voice header from DL1ESZ to TG 9
// M: 2016-04-30 14:57:43.072 DMR Slot 2, received RF voice header from DG9VH to 5000
function getHeardList($logLines) {
	array_multisort($logLines,SORT_DESC);
	$heardList = array();
	$ts1duration = "";
	$ts1loss = "";
	$ts1ber = "";
	$ts2duration = "";
	$ts2loss = "";
	$ts2ber = "";
	$dstarduration = "";
	$dstarloss = "";
	$dstarber = "";
	$ysfduration = "";
    $ysfloss = "";
    $ysfber = "";
	foreach ($logLines as $logLine) {
		$duration = "";
		$loss = "";
		$ber = "";
		//removing invalid lines
		if(strpos($logLine,"BS_Dwn_Act")) {
			continue;
		} else if(strpos($logLine,"invalid access")) {
			continue;
		} else if(strpos($logLine,"received RF header for wrong repeater")) {
			continue;
		}
		
		if(strpos($logLine,"end of") || strpos($logLine,"watchdog has expired")) {
			$lineTokens = explode(", ",$logLine);
			
			$duration = strtok($lineTokens[2], " ");
			$loss = $lineTokens[3];
			
			// if RF-Packet, no LOSS would be reported, so BER is in LOSS position
			if (startsWith($loss,"BER")) {
				$ber = substr($loss, 5);
				$loss = "";
			} else {
				$loss = strtok($loss, " ");
				$ber = substr($lineTokens[4], 5);
			}
			
			switch (substr($logLine, 27, strpos($logLine,",") - 27)) {
				case "D-Star":
					$dstarduration = $duration;
					$dstarloss = $loss;
					$dstarber = $ber;
					break;
				case "DMR Slot 1":
					$ts1duration = $duration;
					$ts1loss = $loss;
					$ts1ber = $ber;
					break;
				case "DMR Slot 2":
					$ts2duration = $duration;
					$ts2loss = $loss;
					$ts2ber = $ber;
					break;
				 case "YSF":
                    $ysfduration = $duration;
                    $ysfloss = $loss;
                    $ysfber = $ber;
                    break;
			}
		}
		
		$timestamp = substr($logLine, 3, 19);
		$mode = substr($logLine, 27, strpos($logLine,",") - 27);
		$callsign2 = substr($logLine, strpos($logLine,"from") + 5, strpos($logLine,"to") - strpos($logLine,"from") - 6);
		$callsign = $callsign2;
		if (strpos($callsign2,"/") > 0) {
			$callsign = substr($callsign2, 0, strpos($callsign2,"/"));
		}
		$callsign = trim($callsign);
		
		$id ="";
		if ($mode == "D-Star") {
			$id = substr($callsign2, strpos($callsign2,"/") + 1);
		}
		
		$target = substr($logLine, strpos($logLine, "to") + 3); 
		$source = "RF";
		if (strpos($logLine,"network") > 0 ) {
			$source = "Network";
		}

		switch ($mode) {
			case "D-Star":
				$duration = $dstarduration;
				$loss = $dstarloss;
				$ber = $dstarber;
				break;
			case "DMR Slot 1":
				$duration = $ts1duration;
				$loss = $ts1loss;
				$ber = $ts1ber;
				break;
			case "DMR Slot 2":
				$duration = $ts2duration;
				$loss = $ts2loss;
				$ber = $ts2ber;
				break;
			case "YSF":
                $duration = $ysfduration;
                $loss = $ysfloss;
                $ber = $ysfber;
                break;
		}
		
		// Callsign or ID should be less than 8 chars long, otherwise it could be errorneous
		if ( strlen($callsign) < 8 ) {
			array_push($heardList, array($timestamp, $mode, $callsign, $id, $target, $source, $duration, $loss, $ber));
			$duration = "";
			$loss ="";
			$ber = "";
		}
	}
	return $heardList;
}

function getLastHeard($logLines) {
	$lastHeard = array();
	$heardCalls = array();
	$heardList = getHeardList($logLines);
	foreach ($heardList as $listElem) {
		if ( ($listElem[1] == "D-Star") || ($listElem[1] == "YSF") || (startsWith($listElem[1], "DMR")) ) {
			if(!(array_search($listElem[2]."#".$listElem[1].$listElem[3], $heardCalls) > -1)) {
				array_push($heardCalls, $listElem[2]."#".$listElem[1].$listElem[3]);
				array_push($lastHeard, $listElem);
			}
		}
	}
	return $lastHeard;
}

function getActualMode($logLines) {
	array_multisort($logLines,SORT_DESC);
	foreach ($logLines as $logLine) {
		if (strpos($logLine, "Mode set to")) {
			return substr($logLine, 39);
		}	
	}
	return "Idle";
}

function getDSTARLinks() {
	$out = "<table>";
	if ($linkLog = fopen(LINKLOGPATH,'r')) {
		while ($linkLine = fgets($linkLog)) {
			$linkDate = "&nbsp;";
			$protocol = "&nbsp;";
			$linkType = "&nbsp;";
			$linkSource = "&nbsp;";
			$linkDest = "&nbsp;";
			$linkDir = "&nbsp;";
// Reflector-Link, sample:
// 2011-09-22 02:15:06: DExtra link - Type: Repeater Rptr: DB0LJ	B Refl: XRF023 A Dir: Outgoing
// 2012-04-03 08:40:07: DPlus link - Type: Dongle Rptr: DB0ERK B Refl: REF006 D Dir: Outgoing
// 2012-04-03 08:40:07: DCS link - Type: Repeater Rptr: DB0ERK C Refl: DCS001 C Dir: Outgoing
			if(preg_match_all('/^(.{19}).*(D[A-Za-z]*).*Type: ([A-Za-z]*).*Rptr: (.{8}).*Refl: (.{8}).*Dir: (.{8})/',$linkLine,$linx) > 0){
				$linkDate = $linx[1][0];
				$protocol = $linx[2][0];
				$linkType = $linx[3][0];
				$linkSource = $linx[4][0];
				$linkDest = $linx[5][0];
				$linkDir = $linx[6][0];
			}
// CCS-Link, sample:
// 2013-03-30 23:21:53: CCS link - Rptr: PE1AGO C Remote: PE1KZU	Dir: Incoming
			if(preg_match_all('/^(.{19}).*(CC[A-Za-z]*).*Rptr: (.{8}).*Remote: (.{8}).*Dir: (.{8})/',$linkLine,$linx) > 0){
				$linkDate = $linx[1][0];
				$protocol = $linx[2][0];
				$linkType = $linx[2][0];
				$linkSource = $linx[3][0];
				$linkDest = $linx[4][0];
				$linkDir = $linx[5][0];
			}
// Dongle-Link, sample: 
// 2011-09-24 07:26:59: DPlus link - Type: Dongle User: DC1PIA	Dir: Incoming
// 2012-03-14 21:32:18: DPlus link - Type: Dongle User: DC1PIA Dir: Incoming
			if(preg_match_all('/^(.{19}).*(D[A-Za-z]*).*Type: ([A-Za-z]*).*User: (.{6,8}).*Dir: (.*)$/',$linkLine,$linx) > 0){
				$linkDate = $linx[1][0];
				$protocol = $linx[2][0];
				$linkType = $linx[3][0];
				$linkSource = "&nbsp;";
				$linkDest = $linx[4][0];
				$linkDir = $linx[5][0];
			}
			$out .= "<tr><td>" . $linkSource . "</td><td>&nbsp;" . $protocol . "-link</td><td>&nbsp;to&nbsp;</td><td>" . $linkDest . "</td><td>&nbsp;" . $linkDir . "</td></tr>";
		}
	}
	$out .= "</table>";
	return $out;
}

function getActualLink($logLines, $mode) {
//M: 2016-05-02 07:04:10.504 D-Star link status set to "Verlinkt zu DCS002 S"
//M: 2016-04-03 16:16:18.638 DMR Slot 2, received network voice header from 4000 to 2625094
//M: 2016-04-03 19:30:03.099 DMR Slot 2, received network voice header from 4020 to 2625094
	array_multisort($logLines,SORT_DESC);
	switch ($mode) {
    case "D-Star":
		return getDSTARLinks();
        break;
    case "DMR Slot 1":
        foreach ($logLines as $logLine) {
        	if(substr($logLine, 27, strpos($logLine,",") - 27) == "DMR Slot 1") {
	        	$from = substr($logLine, strpos($logLine,"from") + 5, strpos($logLine,"to") - strpos($logLine,"from") - 6);
				if (strlen($from) == 4 && startsWith($from,"4")) {
					if ($from == "4000") {
						return "not linked";
					} else {
						return $from;
					}
				}
        	}
		}
		return "not linked";
        break;
    case "DMR Slot 2":
        foreach ($logLines as $logLine) {
        	if(substr($logLine, 27, strpos($logLine,",") - 27) == "DMR Slot 2") {
	        	$from = substr($logLine, strpos($logLine,"from") + 5, strpos($logLine,"to") - strpos($logLine,"from") - 6);
				if (strlen($from) == 4 && startsWith($from,"4")) {
					if ($from == "4000") {
						return "not linked";
					} else {
						return $from;
					}
				}
        	}
		}
		return "not linked";
        break;
	}
	return "something went wrong!";
}

//Some basic inits
$logLines = getLog();
?>