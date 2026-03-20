<?php
/**
 * Build WHERE clause + bound params for SystemEvents list/summary APIs.
 * Whitelists SQL column names; invalid filterset tokens return a structured error.
 */

declare(strict_types=1);

/** @var list<string> */
const SYSTEMEVENTS_FILTER_COLUMNS = [
	'FromHost',
	'Facility',
	'DeviceReportedTime',
	'Priority',
	'SysLogTag',
	'Messagetype',
];

/**
 * @return array{ok:true,where:string,params:array<string,mixed>}|array{ok:false,error:string,detail?:string}
 */
function build_systemevents_search_where(string $searchstring, string $keywordstring): array
{
	$wherestring = '';
	$qArray = [];

	if ($searchstring !== '') {
		$wherestring = '';
		$urlencoded = trim(urldecode($searchstring));
		$array = explode(' ', $urlencoded);

		for ($x = 0; $x < count($array); $x++) {
			$token = trim($array[$x]);
			if ($token === '') {
				continue;
			}

			$keysarray = str_split($token);
			$keyname = '';
			$keyvalue = '';
			$expression = '';
			$position = 0;

			for ($y = 0; $y < count($keysarray); $y++) {
				if ($position === 3 && $keysarray[$y] === '"') {
					$position = 4;
				}
				if ($position === 3 && $keysarray[$y] !== '"') {
					$keyvalue .= $keysarray[$y];
				}
				if ($position === 2 && $keysarray[$y] === '"') {
					$position = 3;
				}
				if ($position === 2 && $keysarray[$y] !== '"') {
					$expression .= $keysarray[$y];
				}
				if ($position === 1 && $keysarray[$y] === '"') {
					$position = 2;
				}
				if ($position === 1 && $keysarray[$y] !== '"') {
					$keyname .= $keysarray[$y];
				}
				if ($position === 0 && $keysarray[$y] === '"') {
					$position = 1;
				}
			}

			if ($keyname === 'Host') {
				$keyname = 'FromHost';
			}
			if ($keyname === 'Facility') {
				$keyname = 'Facility';
				if ($keyvalue === 'KERNEL-MESSAGE') {
					$keyvalue = '0';
				}
				if ($keyvalue === 'USER-MESSAGE') {
					$keyvalue = '1';
				}
				if ($keyvalue === 'MAIL-SYSTEM') {
					$keyvalue = '2';
				}
				if ($keyvalue === 'SECURITY-DAEMON') {
					$keyvalue = '3';
				}
				if ($keyvalue === 'AUTH-MESSAGE') {
					$keyvalue = '4';
				}
				if ($keyvalue === 'SYSLOGD') {
					$keyvalue = '5';
				}
				if ($keyvalue === 'PRINTER') {
					$keyvalue = '6';
				}
				if ($keyvalue === 'NETWORK') {
					$keyvalue = '7';
				}
				if ($keyvalue === 'UUCP') {
					$keyvalue = '8';
				}
				if ($keyvalue === 'CRON') {
					$keyvalue = '9';
				}
				if ($keyvalue === 'AUTH-MESSAGE-10') {
					$keyvalue = '10';
				}
				if ($keyvalue === 'FTP') {
					$keyvalue = '11';
				}
				if ($keyvalue === 'NTP') {
					$keyvalue = '12';
				}
				if ($keyvalue === 'LOG-AUDIT') {
					$keyvalue = '13';
				}
				if ($keyvalue === 'LOG-ALERT') {
					$keyvalue = '14';
				}
				if ($keyvalue === 'CLOCK-DAEMON') {
					$keyvalue = '15';
				}
				if ($keyvalue === 'LOCAL0') {
					$keyvalue = '16';
				}
				if ($keyvalue === 'LOCAL1') {
					$keyvalue = '17';
				}
				if ($keyvalue === 'LOCAL2') {
					$keyvalue = '18';
				}
				if ($keyvalue === 'LOCAL3') {
					$keyvalue = '19';
				}
				if ($keyvalue === 'LOCAL4') {
					$keyvalue = '20';
				}
				if ($keyvalue === 'LOCAL5') {
					$keyvalue = '21';
				}
				if ($keyvalue === 'LOCAL6') {
					$keyvalue = '22';
				}
				if ($keyvalue === 'LOCAL7') {
					$keyvalue = '23';
				}
			}
			if ($keyname === 'Date') {
				$keyname = 'DeviceReportedTime';
			}
			if ($keyname === 'Severity') {
				$keyname = 'Priority';
				$keyvalue = strtoupper($keyvalue);
				if ($keyvalue === 'EMERGENCY') {
					$keyvalue = '0';
				}
				if ($keyvalue === 'ALERT') {
					$keyvalue = '1';
				}
				if ($keyvalue === 'CRITICAL') {
					$keyvalue = '2';
				}
				if ($keyvalue === 'ERROR') {
					$keyvalue = '3';
				}
				if ($keyvalue === 'WARNING') {
					$keyvalue = '4';
				}
				if ($keyvalue === 'NOTICE') {
					$keyvalue = '5';
				}
				if ($keyvalue === 'INFO') {
					$keyvalue = '6';
				}
				if ($keyvalue === 'DEBUG') {
					$keyvalue = '7';
				}
			}
			if ($keyname === 'Syslogtag') {
				$keyname = 'SysLogTag';
			}
			if ($keyname === 'Messagetype') {
				$keyname = 'Messagetype';
			}

			if ($expression !== '=' && $expression !== '<>' && $expression !== '<' && $expression !== '>') {
				return ['ok' => false, 'error' => 'invalid_filter_operator', 'detail' => $expression];
			}

			if (!in_array($keyname, SYSTEMEVENTS_FILTER_COLUMNS, true)) {
				return ['ok' => false, 'error' => 'invalid_filter_field', 'detail' => $keyname];
			}

			if ($expression === '=') {
				$expression = 'LIKE';
			}

			$paramKey = 'param' . (string) $x;
			$qArray[$paramKey] = $keyvalue;

			if ($wherestring === '') {
				$wherestring = ' WHERE ' . $keyname . ' ' . $expression . ' :' . $paramKey . ' ';
			} else {
				$wherestring .= ' AND ' . $keyname . ' ' . $expression . ' :' . $paramKey . ' ';
			}
		}
	}

	if (trim($keywordstring) !== '') {
		$keywordstring = trim(urldecode($keywordstring));
		$qArray['q'] = '%' . $keywordstring . '%';
		$kwClause = ' (Message LIKE :q OR FromHost LIKE :q OR SysLogTag LIKE :q) ';
		if ($wherestring === '') {
			$wherestring = ' WHERE ' . $kwClause;
		} else {
			$wherestring .= ' AND ' . $kwClause;
		}
	}

	return ['ok' => true, 'where' => $wherestring, 'params' => $qArray];
}
