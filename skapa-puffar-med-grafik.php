<?php
	chdir(dirname(__FILE__));


	// Settings
	$resultsUrl = 'http://www.val.se/val/val2010/valnatt/valnatt.zip';
	$localFile = '/tmp/valnatt.zip';
	$exportDir = 'export/';
	$eceSourceStr = 'val2010';
	$createArticle	= FALSE;	// Set to TRUE on first import

	// The files we're interested in from the .ZIP archive
	$resultsFiles = array('valnatt_00R.xml', 'valnatt_00L.xml', 'valnatt_00K.xml');

	// XPath queries to fetch data
	$queries = array(
		'/VAL[@VALTYP="Kommunfullmäktigval"]//LÄN[@NAMN="Skåne län"]/KRETS_RIKSDAG/KOMMUN[@NAMN="Bjuv"]',
		'/VAL[@VALTYP="Kommunfullmäktigval"]//LÄN[@NAMN="Skåne län"]/KRETS_RIKSDAG/KOMMUN[@NAMN="Båstad"]',
		'/VAL[@VALTYP="Kommunfullmäktigval"]//LÄN[@NAMN="Skåne län"]/KRETS_RIKSDAG/KOMMUN[@NAMN="Höganäs"]',
		'/VAL[@VALTYP="Kommunfullmäktigval"]//LÄN[@NAMN="Skåne län"]/KRETS_RIKSDAG/KOMMUN[@NAMN="Helsingborg"]',
		'/VAL[@VALTYP="Kommunfullmäktigval"]//LÄN[@NAMN="Skåne län"]/KRETS_RIKSDAG/KOMMUN[@NAMN="Klippan"]',
		'/VAL[@VALTYP="Kommunfullmäktigval"]//LÄN[@NAMN="Skåne län"]/KRETS_RIKSDAG/KOMMUN[@NAMN="Landskrona"]',
		'/VAL[@VALTYP="Kommunfullmäktigval"]//LÄN[@NAMN="Skåne län"]/KRETS_RIKSDAG/KOMMUN[@NAMN="Perstorp"]',
		'/VAL[@VALTYP="Kommunfullmäktigval"]//LÄN[@NAMN="Skåne län"]/KRETS_RIKSDAG/KOMMUN[@NAMN="Svalöv"]',
		'/VAL[@VALTYP="Kommunfullmäktigval"]//LÄN[@NAMN="Skåne län"]/KRETS_RIKSDAG/KOMMUN[@NAMN="Åstorp"]',
		'/VAL[@VALTYP="Kommunfullmäktigval"]//LÄN[@NAMN="Skåne län"]/KRETS_RIKSDAG/KOMMUN[@NAMN="Ängelholm"]',
		'/VAL[@VALTYP="Kommunfullmäktigval"]//LÄN[@NAMN="Skåne län"]/KRETS_RIKSDAG/KOMMUN[@NAMN="Örkelljunga"]',
		'/VAL[@VALTYP="Landstingsval"]//LÄN[@NAMN="Skåne län"]',
		'/VAL[@VALTYP="Riksdagsval"]//NATION[@NAMN="Sverige"]',
	);

	// Code to Escenic sections
	$eceCodeMap = array(
		'00'	=> 'Inrikes',
		'12'	=> 'Skåne',
		'1260'	=> 'Bjuv',
		'1278'	=> 'Båstad',
		'1283'	=> 'Helsingborg',
		'1284'	=> 'Höganäs',
		'1276'	=> 'Klippan',
		'1282'	=> 'Landskrona',
		'1275'	=> 'Perstorp',
		'1214'	=> 'Svalöv',
		'1277'	=> 'Åstorp',
		'1292'	=> 'Ängelholm',
		'1257'	=> 'Örkelljunga',
	);



	/**
	 * Get data from node
	 * @return Object
	 *
	 */
	function getData($element) {
		global $colorMap, $xml;

		$obj = new stdClass;
		$obj->kind = (string)$xml['VALTYP'];
		$obj->name = (string)$element['NAMN'];
		$obj->code = (string)$element['KOD'];
		$obj->counted = (string)$element['KLARA_VALDISTRIKT'];
		$obj->total = (string)$element['ALLA_VALDISTRIKT'];
		$obj->date = strtotime((string)$element['TID_RAPPORT']);

		$obj->images = array();
		$obj->labels = array();
		$obj->data = array();
		$obj->colors = array();
		$obj->maxdata = 0.0;
		foreach($element->xpath('GILTIGA|ÖVRIGA_GILTIGA') as $node) {
			$party = $node->getName() === 'GILTIGA'? (string)$node['PARTI'] : 'ÖVR';
			$obj->labels[] = $party;
			$obj->colors[] = $colorMap[$party];
			$obj->data[] = str_replace(',', '.', (string)$node['PROCENT']) / 100.0;
		}

		foreach($obj->data as $value)
			if($value > $obj->maxdata)
				$obj->maxdata = $value;

		return $obj;
	}


	if(@chdir($exportDir) === FALSE) {
		@mkdir($exportDir, 0755);
		if(@chdir($exportDir) === FALSE)
			die("Failed to enter export directory '$exportDir'\n");
	}



	// Initialize cURL
	$c = curl_init($resultsUrl);
	curl_setopt($c, CURLOPT_TIMEOUT, 15);
	curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($c, CURLOPT_FOLLOWLOCATION, 1);
	curl_setopt($c, CURLOPT_FILETIME, 1);		// Needed to get Last-Modified: time from curl_getinfo()

	if(file_exists($localFile)) {
		// Prepare If-Modified-Since: header (conditional GET)	
		curl_setopt($c, CURLOPT_TIMEVALUE, filemtime($localFile));
		curl_setopt($c, CURLOPT_TIMECONDITION, CURL_TIMECOND_IFMODSINCE);
	}


	$data = curl_exec($c);
	if($data === FALSE)
		die("Failed to fetch results: ". curl_error($c) ."\n");

	$info = curl_getinfo($c);
	if($info['http_code'] == 304) {
		echo "* Data not modified\n";
		die;
	}
	else if($info['http_code'] != 200) {
		echo "* Unknown HTTP error ". $info['http_code'] ."\n";
		die;
	}

	if(($fd = @fopen($localFile, 'w')) === FALSE)
		die("Failed to save data to '$localFile'\n");

	fwrite($fd, $data);
	fclose($fd);
	touch($localFile, $info['filetime']);

	// Get rid of cURL handle; can't reuse due to sticky CURLOPT_TIMECOND options
	curl_close($c);



	// Loop over entries in ZIP file and extract XML for the interesting files
	$archive = zip_open($localFile);
	if(!is_resource($archive))
		die("Broken .ZIP archive or Ubuntu 8.04 (see https://bugs.launchpad.net/ubuntu/hardy/+source/php5/+bug/406303)\n");


	$objs = array();
	while($entry = zip_read($archive)):

		// Match filename
		$name = zip_entry_name($entry);
		if(!in_array($name, $resultsFiles))
			continue;

		// Extract data
		zip_entry_open($archive, $entry, 'r');
		$data = zip_entry_read($entry, zip_entry_filesize($entry));
		zip_entry_close($entry);

		// Load XML
		$xml = @simplexml_load_string($data);
		if($xml === FALSE)
			continue;

		$kind = (string)$xml['VALTYP'];
		$colorMap = array();
		foreach($xml->xpath('//PARTI') as $element) {
			$party = (string)$element['FÖRKORTNING'];
			$colorMap[$party] = str_replace('#', '', (string)$element['FÄRG']);
		}


		echo "* Processing $name\n";
		foreach($xml->xpath(implode('|', $queries)) as $element):


			$obj = getData($element);
			$objs[] = $obj;

		endforeach;	// xpath query

	endwhile;	// zip entry

	zip_close($archive);



	// Initialize cURL for the Google Charts API calls
	$c = curl_init();
	curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($c, CURLOPT_FOLLOWLOCATION, 1);
	curl_setopt($c, CURLOPT_TIMEOUT, 15);

	foreach($objs as $obj):

		// See http://code.google.com/apis/chart/docs/chart_params.html
		$chartOptions		= array();
		$chartOptions['chs']	= '486x150';		// Width and height
		$chartOptions['cht']	= 'bvs';		// Chart type is stacked bar chart
		$chartOptions['chbh']	= 'r,0.3,0.0';		// Bar spacing is relative, with 30% unit width spacing
		$chartOptions['chts']	= '0000000,18';		// Title color, font size
		$chartOptions['chtt']	= sprintf('%s i %s|%d av %d distrikt räknade kl %s', $obj->kind, $eceCodeMap[$obj->code], $obj->counted, $obj->total, strftime('%H:%M', $obj->date));
		$chartOptions['chm']	= 'N*p1*,000000,-1,-1,11,,r:15:10';			// Bar formatting; Percent with 1 decimal, black, ...
		$chartOptions['chxt']	= 'x';							// 
		$chartOptions['chd']	= 't:'. implode(',', $obj->data);			// Data
		$chartOptions['chco']	= implode('|', $obj->colors);				// Bar colors
		$chartOptions['chxl']	= '0:|'. implode('|', $obj->labels);			// X-axis tick labels
		$chartOptions['chds']	= sprintf('%.2f,%.2f', 0.0, $obj->maxdata + 0.10);	// Y-axis scaling


		/*
		Sample URL:
		http://chart.apis.google.com/chart?chs=486x200&cht=bvs&chcb=r,0.3,0.0&chts=0000000,18&chtt=Riksdagsval i Svalöv|8 av 8 räknade 20:33&chm=N*p0*,000000,-1,-1,11,,r:15:10&chxt=x&chd=t:0.267|0.081|0.078|0.067|0.357|0.06|0.054|0.029|0.006&chco=66BEE6|63A91D|3399FF|1B5CB1|FF0000|C40000|008000|4E83A3|999999&chxl=0:|M|C|FP|KD|S|V|MP|SD|ÖVR&chds=0.00,0.46
		*/


		foreach(array('e', 'c', 'h') as $imageversion) {
			switch($imageversion) {
			case 'e':
				$chartOptions['chs']	= '486x200';		// Width and height
				$chartOptions['chts']	= '000000,18';		// Title color, font size
				$chartOptions['chxs']	= '0,000000,14';	// Tick labels color, font size
				$chartOptions['chm']	= 'N*p1*,000000,-1,-1,13,,r:16:10';			// Bar formatting; Percent with 1 decimal, black, ...
				break;
			case 'h':
				$chartOptions['chs']	= '250x125';		// Width and height
				$chartOptions['chts']	= '000000,14';		// Title color, font size
				$chartOptions['chxs']	= '0,000000,9';	// Tick labels color, font size
				$chartOptions['chm']	= 'N*p0*,000000,-1,-1,11,,r:13:10';			// Bar formatting; Percent with 1 decimal, black, ...
				break;
			case 'c':
				$chartOptions['chs']	= '200x125';		// Width and height
				$chartOptions['chts']	= '000000,11';		// Title color, font size
				$chartOptions['chxs']	= '0,000000,7';		// Tick labels color, font size
				$chartOptions['chm']	= 'N*p0*,000000,-1,-1,8,,r:10:10';			// Bar formatting; Percent with 1 decimal, black, ...
				break;
			default:
				break;
			}


			$querystring = array();
			foreach($chartOptions as $param => $value) $querystring[] = $param .'='. urlencode($value);
			$url = 'http://chart.apis.google.com/chart?'. implode('&', $querystring);

			echo "URL: ". urldecode($url) ."\n";

			curl_setopt($c, CURLOPT_URL, $url);
			if(($data = curl_exec($c)) === FALSE)
				continue;


			$filename = $eceSourceStr .'-'. $eceCodeMap[$obj->code] .'-'. $obj->kind .'-'. $imageversion .'.png';
			if(($fd = @fopen($filename, 'w')) === FALSE)
				continue;

			fwrite($fd, $data);
			fclose($fd);
			chmod($filename, 0666); // Stupid Escenic 4.3-{4,5,6,7,8} bug

			$obj->images[$imageversion] = $filename;
		}


		// Construct XML for Escenic
		$xml = '<?xml version="1.0" encoding="UTF-8" ?>';
		$xml .= '<io>';
		$xml .= '<multimediaGroup id="voteimage" type="image" source="'. htmlspecialchars($eceSourceStr) .'" sourceid="votes-'. htmlspecialchars($eceCodeMap[$obj->code]) .'" name="'. htmlspecialchars($eceCodeMap[$obj->code]) .'-'. htmlspecialchars($eceSourceStr) .'.png" catalog="imported" copyright="HD" alttext="Röstresultat i '. htmlspecialchars($eceCodeMap[$obj->code]) .' kl '. strftime('%H:%M', $obj->date) .'">';
		$xml .= '  <description>Rösträkningen i '. htmlspecialchars($eceCodeMap[$obj->code] .' ('. $obj->kind .')', ENT_NOQUOTES) .' just nu</description>';
		foreach($obj->images as $version => $filename)
			$xml .= '  <multimedia version="'. htmlspecialchars($version) .'" filename="'. htmlspecialchars($filename) .'" />';
		$xml .= '</multimediaGroup>';


		if($createArticle) {
			$xml .= '<article type="puff" source="'. htmlspecialchars($eceSourceStr) .'" sourceid="'. htmlspecialchars($eceCodeMap[$obj->code] .'-'. $obj->kind) .'" state="published" publishdate="2010-09-19 20:00:00">';
			$xml .= '  <field name="headline">Följ rösträkningen i '. htmlspecialchars($obj->name, ENT_NOQUOTES) .'</field>';
			$xml .= '  <field name="headlineVignetteText">Just nu: rösträkningen pågår</field>';
			$xml .= '  <field name="headlineVignetteStyleColor">special</field>';
			$xml .= '  <field name="headlineVignetteStyleIcon">clock</field>';
			$xml .= '  <field name="readmore">Till valresultatet</field>';
			$xml .= '  <reference id="voteimage" type="image" element="default" />';
			$xml .= '  <section name="'. htmlspecialchars($eceCodeMap[$obj->code]) .'" homeSection="true" />';
			$xml .= '  <section name="Val 2010" />';
			$xml .= '</article>';
		}


		$xml .= '</io>';

		if(($fd = @fopen($eceSourceStr .'-'. $eceCodeMap[$obj->code] .'-'. $obj->kind .'.xml', 'w')) !== FALSE) {
			fwrite($fd, $xml);
			fclose($fd);
		}

	endforeach;

	curl_close($c);
