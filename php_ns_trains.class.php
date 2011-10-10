<?php

class PhpNsTrains {

	/* API Base URL setting
	* Note: currently HTTP, couldn't get the HTTPS to work so far
	*/
	private static $base_url = "http://webservices.ns.nl/";
	
	private $authUser;
	private $authPassword;

	/* Constructor, takes API username and password obtainable from http://www.ns.nl/api */
	function __construct($username, $password) {
		$this->authUser = $username;
		$this->authPassword = $password;
	}
	
	/* 	
	 * Return a list of stations, optionally an associative array with the
	 * given key from the return values (name, code, lat or long). Second parameter 
	 * specifies whether or not to only include Dutch train stations
	 * NOTE: In most normal use cases, cache this result
	 */
	function getStations($key = null, $nlOnly = false) {
		$xmlTree = $this->getUrl('ns-api-stations');
		
		if (!in_array($key, array('name', 'code', 'lat', 'long')))
			$key = null;
			
		$output = array();
		foreach($xmlTree as $xmlStation) {
			$station = (array) $xmlStation;
			if ($nlOnly && $station['country'] == 'NL') { // Check if dutch
				if ($key) {
					$output[$station[$key]] = $station;
				} else {
					$output[] = $station;
				}
			}
		}
		return $output;
	}
	
	/* 
	 * Get a list of current service disruptions. Options are:
	 *  - 'station': filtered for given station
	 *  - 'actual': show current disruptions? (boolean)
	 *  - 'unplanned': show planned engineering works? (boolean)
	 *  
	 *  TODO: Handle 'bericht' field and test unplanned disruptions and 
	 *  add a helper function here
	 */
	function getDisruptions($options = array()) {
		$xmlTree = $this->getUrl('ns-api-storingen', $options);

		$disruptions = array();
		foreach($xmlTree->Gepland->Storing as $xmlNotice){
			$notice = (array) $xmlNotice; 
			$disruptions[] = array('id' => $notice['id'], 'applicable' => $notice['Traject'], 'period' => $notice['Periode'], 
				'alternative' => $notice['Advies'], 'reason' => $notice['Reden'], 'delay' => $notice['Vertraging'], 'type' => 'planned');
		}
		foreach($xmlTree->Ongepland->Storing as $xmlNotice){
			$notice = (array) $xmlNotice; 
			$disruptions[] = array('id' => $notice['id'], 'applicable' => $notice['Traject'], 'period' => $notice['Periode'], 
				'alternative' => $notice['Advies'], 'reason' => $notice['Reden'], 'delay' => $notice['Vertraging'], 'type' => 'unplanned');
		}
		
		return $disruptions;
	}
	
	/* 
	 * Get a live list of departures for a given station, optionally with an name of key to use for the index
	 */
	function getDepartures($station, $key = null) {
		$xmlTree = $this->getUrl('ns-api-avt', array('station' => $station));
		$output = array();
		// Loop over each train entry
		foreach($xmlTree as $xmlTrain) {
			// Cast as an array to get access to most keys
			$train = (array) $xmlTrain;	
			$add = array('departure' => strtotime($train['VertrekTijd']), 'service' => $train['RitNummer'],
				'destination' => $train['EindBestemming'], 'type' => $train['TreinSoort'], 
				'platform' => $train['VertrekSpoor'], 'via' => $train['RouteTekst'] ? $train['RouteTekst'] : "");
			
			// Decode any (optional) delay to a integer minute value
			if (isset($train['VertrekVertraging'])) {
				if (preg_match('/^PT(\d{0,3}?)M$/', $train['VertrekVertraging'], $matches)) {
					$add['delay'] = $matches[1];
				}
			}

			// Check if the platform was changed
			$changed = false;
			if($xmlTrain->VertrekSpoor->attributes()) {
				$attr = (array) $xmlTrain->VertrekSpoor->attributes();
				if (isset($attr['@attributes']['wijziging']) && $attr['@attributes']['wijziging'] == "true")
					$changed = true;
			}
			$add['platform_changed'] = $changed;
			
			// Add the train to our output list
			$output[] = $add;
		}
		return $output;
	}
	
	/* 
	 * List the available travel options given a origin and destination.
	 * Several options can also be set:
	 * 	- 'previousAdvices': number of ravel options to list in the past (max 5)
	 *  - 'nextAdvices':  number of ravel options to list in the future (max 5)
	 *  - 'dateTime': arrival or departure time
	 *  - 'departure': is the above parameter arrival or departure (boolean)
	 *  - 'hslAllowed': also use highspeed trains? (boolean) - default: true
	 *  - 'yearCard': assume free travel? (boolean) - default: false
	 *  
	 *  TODO: Add support for notices/detection about invalid connections 
	 */
	function getTrips($from, $to, $options = array()) {
		if (!empty($options['dateTime'])) {
			$options['dateTime'] = date('c', strtotime($options['dateTime']));
		}
		$xmlTree = $this->getUrl('ns-api-treinplanner', array_merge(array('fromStation' => $from, 'toStation' => $to), $options)); 
		
		$output = array();
		// Loop over each option
		foreach($xmlTree as $xmlTrip) {
			$trip = (array) $xmlTrip;
			$tripOption = array('duration_scheduled' => self::hourMinutesToSeconds($trip['GeplandeReisTijd']), 
				'duration_actual' => self::hourMinutesToSeconds($trip['ActueleReisTijd']), 
				'optimal' => $trip['Optimaal'], 'departure_actual' => $trip['ActueleVertrekTijd'], 'departure_scheduled' => $trip['GeplandeVertrekTijd'],
				'departure_actual' => strtotime($trip['ActueleVertrekTijd']), 'departure_scheduled' => strtotime($trip['GeplandeVertrekTijd']),
				'arrival_actual' => strtotime($trip['ActueleVertrekTijd']), 'arrival_scheduled' => strtotime($trip['GeplandeVertrekTijd']),
				'changes' => $trip['AantalOverstappen']
			);
			
			// Loop over each part of the option
			foreach ($xmlTrip->ReisDeel as $xmlPart) {
				$part = (array) $xmlPart;
				$stops = array();
				
				// Loop over each stop
				foreach ($xmlPart->ReisStop as $xmlStop) {
					$stop = (array) $xmlStop;
					$curStop = array('station' => $stop['Naam'], 'time' => strtotime($stop['Tijd']));
					if (!empty($stop['Spoor'])) {
						$curStop['platform'] = $stop['Spoor'];
					}
					$stops[] = $curStop;
				}
				
				$tripOption['connections'][] = array('mode' => strtolower($part['@attributes']['reisSoort']), 
					'type' => $part['VervoerType'], 'service' => $part['RitNummer'], 'stops' => $stops);
			}
			$output[] = $tripOption;
		}
		return $output;
	}
	
	/* 
	 * Get a list of prices for a give to/from trip 
	 * Lists class as 1 or 2 (first/second) and discount as (0, 20 or 40%)
	 */
	function getPrices($from, $to, $via = null) {
		$xmlTree = $this->getUrl('ns-api-prijzen-v2', array('from' => $from, 'to' => $to, 'via' => $via)); 
		
		$output = array();
		foreach($xmlTree->Product as $product) {
			$productArray = (array) $product;
			foreach ($product->Prijs as $price) {
				$price = (array) $price;
				switch ($price['@attributes']['korting']) {
					case "reductie_20": $discount = 20; break;
					case "reductie_40": $discount = 40; break;
					case "vol tarief": $discount = 0; break;
					default: $discount = 20; break;
				}
				$output[] = array('product' => $productArray['@attributes']['naam'], 
					'class' => $price['@attributes']['klasse'], 'discount' => $discount, 'price' => $price[0]);
			}
		}
		
		return $output; 		
	}
	
	// UTILITY FUNCTIONS
	
	/* 
	 * Convert hours and minutes seperated by a colon to seconds
	 */
	private function hourMinutesToSeconds($input) {
		$input = explode(':', $input);
		return 60*($input[1]+ ($input[0]*60));
	}
	
	/* 
	 * Internal functioning for downloading data
	 * TODO: Add support for HTTPS and/or CURL 
	 */
	private function getUrl($endpoint, $vars = array()) {
	
		// Write query string
		$query = "?";
		foreach($vars as $key => $value) {
			if ($value != "") {
				$query .= $key."=".$value."&";
			}
		}
		$query = rtrim($query, '&'); 
		$url = self::$base_url . $endpoint . $query;

		// Create context to be able to specify authentication
		$context = stream_context_create(array(
			'http' => array(
				'header'  => "Authorization: Basic " . base64_encode($this->authUser.":".$this->authPassword)
			)
		));
		$data = file_get_contents($url, false, $context);
		if (!$data)
			return false;
		
		// Parse the result
		$xmlTree = simplexml_load_string($data);
		
		return $xmlTree;
	}

}

?>