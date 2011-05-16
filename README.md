PhpNsTrains
======

This is a PHP API for accessing the NS (Dutch Railways) API / WebService. Details for this service and signing up (account is required, rate limited to 50,000 requests per service, per day) [are documented here](http://ns.nl/api).

Currently supported is:

* List of train stations
* Live departures
* List of disruptions
* Trip planning and fares

Basic usage example
-------------------

	require ('ns_api.class.php');
	$ns = new NsApi(API_ACCOUNT, API_KEY);
	$ns->getStations('code', true);