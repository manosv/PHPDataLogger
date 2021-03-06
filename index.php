<?php 
// Documatation can be found here https://github.com/funvill/PHPDataLogger
/***
 * Start a timer at the start of the file. This timer will be used to detect how
 * long it took to process this request. 
 */ 
$scriptTimeStart = microtime(true);

 // Settings: 
define( 'SETTING_DATABASE', 'database.sqlite' );
define( 'SETTING_CALLHOME', '192.241.237.216:3333' );


// $page['settings']['database'] 			= 'database.sqlite' ; 
// $page['settings']['callhome']			= '192.241.237.216:3333' ; // Set to false to disable. 
// $page['settings']['enabled_methods'] 	= array('post', 'get' ) ;


 // Constants 
define( 'API_VERSION', 		'1'  );
define( 'DEFAULT_LIMIT', 	'30' );
define( 'DEFAULT_OFFSET', 	'0'  );



class CDataLogger
{
	// Public
	// ------------------------------------------------------------------------
	public $page ; 
	
	function __construct() {
		
		// Ensure that this server has the required php functions
		// PHP version >= 5.2.x 
		$php_version = explode('.', PHP_VERSION);
		if ($php_version[0] < 5 || ( $php_version[0] >= 5 && $php_version[1] < 2 ) ) {
			die( "Error: PHP version 5.2 or greater required. current version=". $php_version ); 
		}
		
		if (function_exists("sqlite_libversion")) {
			// Sqlite, >= 3.x
			$sqlite_version = explode('.', sqlite_libversion() );
			if( $sqlite_version[0] < 3 ) {
				die( "Error: Sqlite version 3.x or greater required. current version=". sqlite_libversion() ) ; 
			}
		} else {
			die( "Error: Sqlite version 3.x or greater required. SQLite not currently installed" ) ; 
		}
		
		
		$this->page['settings']['database'] = SETTING_DATABASE ; 
		$this->ConnctToDatabase() ; 
	}

	public function ProccessRequest() {

		// Build the Request. 
		$this->BuildRequest();

		// Build the Response. 
		$this->BuildResponse();

		// Render
		$this->Render(); 
	}




	// Private 
	// ------------------------------------------------------------------------

	// Connects to the database and sets up the dbhandle for future requests. 
	private function ConnctToDatabase( ) {
		// Attempt to connect to the database 
		$this->page['dbhandle'] = new SQLite3( $this->page['settings']['database'] ); 

		// Check to see if the database tables exist. if they do not create them. 
		if( ! $this->page['dbhandle']->exec('CREATE TABLE IF NOT EXISTS data (id INTEGER PRIMARY KEY AUTOINCREMENT, created TIMESTAMP DEFAULT CURRENT_TIMESTAMP, name char(255), value char(255) )') ) {
			throw new Exception( "Could not create database", 500 ); 
		}
	}

	// Query the database. 
	private function Query( $sql_query ) {
		// Get the value 
		$data = array(); 
		$results = $this->page['dbhandle']->query( $sql_query ) ;
		if( $results !== false ) {
			while ($row = $results->fetchArray( SQLITE3_ASSOC ) ) {
				$data[ ] = $row ; 
			} 
		} else {
			// There was an error. 
			$errorCode    = $this->page['dbhandle']->lastErrorCode() ; 
			$errorMessage = $this->page['dbhandle']->lastErrorMsg () ;
			throw new Exception( "SQL query failed, error_code=". $errorCode .', error_message='. $errorMessage. ', sql_query='. $sql_query, 500 ); 
		}

		// Everything looks good. 
		return $data ; 
	}

	private function PostData( $name, $value, $created = false ) {
	 	// Check to see if the created prameter has been filled in. 
	 	if( false === $created) {
	 		$created = date('Y-m-d H:i:s') ; 
	 	}

	 	// Update the database. 
	 	$sql_query = 'INSERT INTO data (created, name, value ) VALUES ( "'. SQLite3::escapeString( $created )  .'", "'. SQLite3::escapeString( $name ) .'", "'. SQLite3::escapeString( $value ) .'" )' ; 
	 	$result = $this->page['dbhandle']->query( $sql_query );
	 	if( ! $result ) {
	 		throw new Exception( 'Could not insert the data', 500 ); 
	 		return false; 
	 	}

	 	// Send the data home if needed. 
	 	$this->callhome( $name, $value, $created ) ; 

	 	// return the ID of the inserted query. 
	 	return $this->page['dbhandle']->lastInsertRowID () ; 
	}

	private function GetData( $name = false, $search = false ) {

		if( true === $search && $name !== false ) {
			// Construct query 
			$sql_query = 'SELECT DISTINCT name FROM data ';
			if( strlen($name) > 0 ) {
				$sql_query .= ' WHERE name LIKE "%'. SQLite3::escapeString( $name ) .'%" ';
			}
		} else if( $name != false ) {
			// Construct query 
			$sql_query = 'SELECT * FROM data ';
			if( strlen($name) > 0 ) {
				$sql_query .= ' WHERE name="'. SQLite3::escapeString( $name ) .'" ';
			}
		} else {
			$sql_query = 'SELECT DISTINCT name FROM data ' ; 
		}

		$sql_query .= ' ORDER BY id DESC '; 
		$sql_query .= ' LIMIT '. SQLite3::escapeString( $this->page['request']['offset'] ).', '. SQLite3::escapeString( $this->page['request']['limit'] ).';' ; 
		return $this->Query( $sql_query ) ; 
	 }

	/***
	 * Creates a UDP packet and sends to the home server. 
	 * To disable this feature set ['settings']['callhome'] to false. 
	 * Because this function uses UDP packet so its very quick ans should not 
	 * slow down the logging of data to the database. 
	 */
	private function CallHome( $name, $value, $created ) {
		if( false === SETTING_CALLHOME ) {
			return ; // Nothing to do here. 
		}

		$socket = stream_socket_client('udp://'. SETTING_CALLHOME );
		if ( false !== $socket) {
			// Send the packet to the server. 
			fwrite($socket, json_encode ( array('name'=>$name, 'value'=>$value, 'created'=>$created ) ) );
			fclose($socket);
		}
	}


	/***
	 * Gets the requsted method. 
	 * Check the url first to explicitly declared. if not declared
	 * the method comes from the http header
	 */
	private function GetRequestMethod( $enabledMethods ) {

		// Check to see if the method was defined. 
		if( isset($_REQUEST['method'] ) ) {
			$method = $_REQUEST['method'] ; 
		} else {
			$method = $_SERVER['REQUEST_METHOD']; 
		}

		// Drop the method case
		$method = strtolower( $method ); 

		// Valadate that the method is allowed. 
		if( ! in_array($method, $enabledMethods ) ) {
			// Error, This method is either unknow or has been disabled. 
			throw new Exception( 'unknown method, method=['. $method .']', 405 ); 
			return false; 
		}

		// Everything looks okay. 
		return $method ; 
	}


	/*** 
	 * Find the response format. 
	 * First we check the url to see if the format is explicitly declared. 
	 * If the format is not set in the url then we check the http headers 
	 * to see if its defined. If the format is not decalred in the header 
	 * then we default to html. 
	 *
	 * Accepted formats
	 * - json, application/json
	 * - html, text/html
	 * - text, text/plain
	 */
	private function GetRequestFormat() {
		// Response format. 
		$request_format = 'html';
		if( isset( $_REQUEST['format'] ) ) {
			$request_format = $_REQUEST['format'] ; 
		} else {
			// Get the request type from the header. 
			if( false !== strpos($_SERVER['HTTP_ACCEPT'], 'text/html') ) {
				$request_format = 'html' ; 
			} else if( false !== strpos($_SERVER['HTTP_ACCEPT'], 'text/plain') ) {
				$request_format = 'plain' ; 
			} else if( false !== strpos($_SERVER['HTTP_ACCEPT'], 'application/json') ) {
				$request_format = 'json' ; 
			} else {
				throw new Exception( 'Unknown request type, type=['. $_SERVER['HTTP_ACCEPT'] .']', 400 ) ; 
			}		
		}

		// Drop the fromat case
		$request_format = strtolower( $request_format ); 
		if( in_array( $request_format, array( 'json','html','text') ) ) {
			// Supported format 
			return $request_format ; 
		} else {
			// Unsupported format. 
			throw new Exception( 'Unknown request type, type=['. $request_format .']', 400 ) ; 
		}
	}

	/***
	 * Check to see what version of the api is being requested. 
	 * Check the url first to xplicitly declared. if not check 
	 * the header or not set then default to the current version. 
	 */
	private function GetAPIVersion() 
	{
		// If the version is not defined, assume that its the current version. 
		$version = API_VERSION ; 
		if( isset($_REQUEST['version'] ) ) {
			$version = $_REQUEST['version'] ; 
		} 

		// Valiadate the version. 
		if($version != API_VERSION ) {
			// Unsupported version 
			throw new Exception( 'Invalid version, Current version=['. API_VERSION .'], Request version=['. $version .']', 400 ) ; 
			return false; 
		}

		// Everything looks good. 
		return $version ; 
	}

	private function BuildRequest() 
	{
		// Build the request. 
		// ------------------------
		
		// Request format. 
		$this->page['request']['format'] = $this->GetRequestFormat(); 

		// Method 
		$this->page['request']['method'] = $this->GetRequestMethod( array('post', 'get' ) ) ;

		// API Version 
		$this->page['request']['version'] = $this->GetAPIVersion(); 

		// Limit and Offset
		$this->page['request']['limit']  = DEFAULT_LIMIT ; 
		$this->page['request']['offset'] = DEFAULT_OFFSET ; 
		if( isset( $_REQUEST['limit'] ) ) {
			$this->page['request']['limit'] = $_REQUEST['limit'] ; 
		}
		if( isset( $_REQUEST['offset'] ) ) {
			$this->page['request']['offset'] = $_REQUEST['offset'] ; 
		}

		// Get the common url prameters. 
		if( isset( $_REQUEST['name']) ) {
			$this->page['request']['name'] = $_REQUEST['name'] ;
		}
		if( isset( $_REQUEST['value']) ) {
			$this->page['request']['value'] = $_REQUEST['value'] ;
		}	 
		if( isset( $_REQUEST['query']) ) {
			$this->page['request']['query'] = $_REQUEST['query'] ; 	
		}
	}


	private function BuildResponse()
	{
		// Build the response
		// ------------------------

		// Add the createdAt, when the response was built. 
		$this->page['response']['createdAt'] = date( 'r' ); 


		// Process the request. 	
		switch( $this->page['request']['method']  ) {
			default: 
			{
				throw new Exception( 'Unsupported method, method=['. $this->page['request']['method'] .']', 405 );
				return; 
				break; 
			}
			case 'get':
			{
				// There are three ways to respond to a GET request depending on the prameters provided. 
				// * Query - Seach the database for a name 
				// * List all - List all the properties 
				// * Get - Get the current values for a perdiculare property

				// Check to see if they are doing a search. 
				if( isset( $this->page['request']['query'] ) ) {
					$this->page['response']['data'] = $this->GetData( $this->page['request']['query'], true ); 
				}
				// Get - Get the current values for a perdiculare property
				else if( isset( $this->page['request']['name'] ) ) {
					$this->page['response']['data'] = $this->GetData( $this->page['request']['name'] ); 
				} 
				// List all - List all the properties 
				else {
					$this->page['response']['data'] = $this->GetData( ); 
				}

				// Check to see if there was anything to report 
				if( count( $this->page['response']['data'] ) > 0 ) {
					$this->page['response']['status'] 	 = 'ok';
					$this->page['response']['status_code'] = '200';
				} else {
					// There was no results to this request. 
					throw new Exception( 'No results to this request.', 204 );
					return ; 
				}
				break; 
			}

			case 'post':
			{
				// The post is used to CREATE data.
				// Required prameters: name, and value 

				if( ! isset( $this->page['request']['name'] ) ) {
					throw new Exception( 'Missing required prameter, name', 400 );
					return ; 
				}
				if( ! isset( $this->page['request']['value'] ) ) {
					throw new Exception( 'Missing required prameter, value', 400 );
					return ; 
				}

				$results = $this->PostData( $this->page['request']['name'], $this->page['request']['value'] );
				if( false === $results ) {
					throw new Exception( 'Could not write value to database', 500 );
					return ; 	
				}

				// Everything looks good here, Respond with okay. 		
				$this->page['response']['status'] 	 	= 'ok';
				$this->page['response']['status_code'] 	= 201;
				$this->page['response']['data'][] = array( 
							'id'      => $results , 						
							'created' => date('Y-m-d H:i:s'),  // '2014-05-03 19:40:02'
							'name'    => $this->page['request']['name'], 
							'value'   => $this->page['request']['value']						
						) ; 

				break; 
			}
		}
	}	


	private function Render()
	{
		global $scriptTimeStart;

		// Get the time that this script took to generate in ms. 
		$this->page['response']['generatedTime'] = round( ( microtime(true) - $scriptTimeStart ) * 1000 , 5 )  .' ms'; 

		// Format the response. 
		if( $this->page['request']['format'] == 'json' ) {
			header('Content-Type: application/json');	
			echo json_encode ( $this->page['response'] ); 
			exit(); 
		} else if( $this->page['request']['format'] == 'text' ) {
			header('Content-Type: text/plain');
			print_r ( $this->page['response'] ); 
			exit(); 
		}

?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Datalogger</title>

    <!-- HTML5 Shim and Respond.js IE8 support of HTML5 elements and media queries -->
    <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
    <!--[if lt IE 9]>
      <script src="https://oss.maxcdn.com/libs/html5shiv/3.7.0/html5shiv.js"></script>
      <script src="https://oss.maxcdn.com/libs/respond.js/1.4.2/respond.min.js"></script>
    <![endif]-->

	<!-- Bootstrap -->
	<link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.1.1/css/bootstrap.min.css">
	<link rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.1.1/css/bootstrap-theme.min.css">
	
	<!-- d3 -->
    <script type="text/javascript" src="./js/jquery-2.0.3.min.js"></script>
    <script type="text/javascript" src="./js/knockout-3.0.0.js"></script>
    <script type="text/javascript" src="./js/globalize.min.js"></script>
    <script type="text/javascript" src="./js/dx.chartjs.js"></script>    


  </head>
  <body>
  	<div class="container-fluid">
  		<div class="row">
  			<div class='col-md-2'></div>
  			<div class='col-md-8'>
			    <h1>DataLogger</h1>
			    <p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Nunc laoreet non ipsum in aliquam. Aliquam erat volutpat. Maecenas rhoncus tempor neque, eget malesuada augue malesuada non. Sed arcu leo, tempus vel adipiscing eget, cursus quis arcu. Maecenas volutpat iaculis dictum. Duis adipiscing vehicula libero ut convallis. Sed quis venenatis est, vitae tincidunt nulla. Nullam metus felis, mollis eget posuere nec, sodales ut leo. In ultricies arcu tortor, a ultrices quam placerat at. Etiam congue lacus tellus, a pharetra tortor tristique vel. Morbi aliquet arcu vitae aliquam dictum. Proin quis ornare nisi, eu dictum purus. Pellentesque dapibus feugiat turpis eu ultricies. Quisque in lorem massa. Aliquam nec tortor quis sem semper dapibus nec non nunc.</p>

			    <p>Source code and documantation: <a href='https://github.com/funvill/PHPDataLogger'>Github</a></p>

				<p><a href='./'>List all properties</a></p>
			    <?php
			    
			    // ToDo: show error when there is an error. 
			    if( isset( $this->page['request']['method'] ) && $this->page['request']['method'] == 'post' ) {
			    	echo '<p class="bg-success" style="padding: 10px">The value of ['. $this->page['request']['value'] .'] was added successfuly to the property [<a href="?method=get&name='. $this->page['request']['name'] .'">'. $this->page['request']['name'] .'</a>]</p>';
			    } else if( @count ($this->page['response']['data'] ) > 0 ) {
					if( isset( $this->page['request']['name'] ) ) {
						// This is an individual property request 
						?>			
						<div id="chartContainerCombined" style="width:100%;height: 600px"></div>			
						<script>var dataLoggingSource = [<?php 
				        foreach( $this->page['response']['data'] as $key => $value ) {
			                echo '{ date: "'. $value['created'] .'", value: '. $value['value'] ."},\n";				            
				        }?>];

$("#chartContainerCombined").dxChart({
    dataSource: dataLoggingSource,
    commonSeriesSettings: { type: "splineArea", argumentField: "date", point: { visible: true },},
    series: [ { valueField: "value", name: "value", color: "#880000" },],
    tooltip: { enabled: true, customizeText: function (arg) { return this.valueText ; } },    
    title: "<?php echo $this->page['request']['name'] ?>",
    argumentAxis:{ valueMarginsEnabled: false, grid: { visible: false },},
    valueAxis: [{ grid: { visible: true }, }],
    legend: { visible: false, }
});
</script>
<?php 
						// Print the table of data. 
						$firstRow = true ; 
						echo '<table class="table table-striped">';
						foreach( $this->page['response']['data'] as $row ) {
							if( $firstRow ) {
								$firstRow = false ;
								echo '<thead><tr>';
								foreach( array_keys( $row ) as $key ) {
									if( $key == 'name' ) {
										continue; 
									}
									echo '<th>'. $key .'</th>';
								}
								echo '</thead></tr><tbody>';
							}
							
							echo '<tr>';								
							foreach( $row as $key=>$value ) {
								if( $key == 'name' ) {
									continue; 
								}
								echo '<td>'. $value .'</td>';
							}
							echo '</tr>';
						}
						echo '</tbody><table>';

					} else {
						// List all the data points 
			    		echo '<h3>Data Points:</h3>';
				    	echo '<ul>';
				    	foreach( $this->page['response']['data'] as $row ) {
				    		if( !isset( $row['name']) ) {
				    			continue; 
				    		}
					    	echo '<li><a href="?act=get&name='. $row['name'] .'">'. $row['name'] .'</a></li>';
				    	}
				    	echo '</ul>';
				    	?>

				    	<h3>Insert value</h3>
				    	<form role="form" method="get">
				    		<input type='hidden' name='method' value='post' />
				    		<div class='row'>
				    			<label for='name' class="col-sm-2 control-label">Name</label>
				    			<div class="col-sm-4">
				    				<select name='name' class="form-control">
				    				<?php 
										foreach( $this->page['response']['data'] as $row ) {
											if( !isset( $row['name']) ) {
												continue; 
											}
											echo '<option value="'. $row['name'] .'">'. $row['name'] .'</option>';
										}
				    				?>
				    				</select>
				    			</div>
							</div>
							<div class='row'>
								<label for='value' class="col-sm-2 control-label">Value</label>
				    			<div class="col-sm-4"><input name='value' type='text' class="form-control" /></div>
				    		</div>

			    			<button type="submit" class="btn btn-default">Submit</button>
				    	</form>

				    	<?php 
				    }
			    }			    	
			    ?>
			</div>
			<div class='col-md-2'></div>
		</div>
	</div>

    <!-- jQuery (necessary for Bootstrap's JavaScript plugins) -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js"></script>
    <!-- Latest compiled and minified JavaScript -->
	<script src="//netdna.bootstrapcdn.com/bootstrap/3.1.1/js/bootstrap.min.js"></script>
  </body>
</html>
<?

		// debug
		/*
		echo '<pre>';
		print_r ( $this->page ); 
		echo '</pre>';
		*/
	
	}

} ; 



$dataLogger = new CDataLogger ();

try {
	$dataLogger->ProccessRequest(); 
} catch (Exception $e) {

	$response['status'] 		= 'error';	
	$response['status_code']	= $e->getCode();	
	$response['error_details']	= $e->getMessage() ;

	$http_code = array(200 => "OK", 201 => "Created", 202 => "Accepted", 204 => "No Content", 400 => "Bad Request", 404 => "Not Found", 405 => "Method Not Allowed", 415 => "Unsupported Media Type", 424 => "Method Failure", 429 => "Too Many Requests", 500 => "Internal Server Error", 501 => "Not Implemented", 507 => "Insufficient Storage");

	if( array_key_exists ($response['status_code'], $http_code ) ) {
		$response['status_message']	= $http_code[ $response['status_code'] ] ; 
	} else {
		$response['status_message'] = 'Error' ; 
	}

	$protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
	// header($protocol . ' ' . $http_code . ' ' . $response['status_message'] );
	header('Content-Type: application/json');	
	echo json_encode ( $response ); 

	// Close the connection 
	exit(); 
}
