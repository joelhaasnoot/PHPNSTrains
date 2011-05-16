<?php 
	define(API_USER, '');
	define(API_PASSWORD, '');
?>
<html>
	<head>
		<title>PHPNSTrains Demos</title>
	</head>
	<body>
		<form action="av.php" method="POST">
			<label for="station">Station:</label>
			<input type="text" id="station" name="station" value="<?php if ($_POST['station']) echo $_POST['station']; ?>" />
			<input type="submit" value="Bekijk"/>
		</form>
<?php
	if ($_POST['station']) {
		require('../php_ns_trains.class.php');
		$ns = new PhpNsTrains(API_USER, API_PASSWORD);
		foreach($ns->getDepartures($_POST['station']) as $train) {
			echo date('H:i', $train['departure']). ' - '.$train['type'].' '.$train['service']. 
				' > <a title="via '.$train['via'].'">'.$train['destination'].'</a> (Platform '.$train['platform'].')'.'<br />';			
		}
	}
?>
	</body>
</html>