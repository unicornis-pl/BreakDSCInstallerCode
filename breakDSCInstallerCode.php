#!/usr/bin/php
<?php
error_reporting(E_ALL);
// Script parameters
$address            = '192.168.1.15'; // IP address of Envisalink module
$port 	            = 4025;
$password           = "user";         // Password to Envisalink (the same as to login to Envisalink module webserver, factory default - user)
$startingCode       = 0;              // Codes are checked with brute force starting from this value till 9999 - useful if you restart the script
$enterInstallerMode = "1*8";          // Partition number and command to enter installer mode, please check the keystrokes for your pannel



$stopTime = 0.0;

function envisalinkLogin ($password) {
   echo ("Logging in to Envisalink...\n");
   $lines = readData();
   foreach($lines as $line)
      echo ("  ".readDSC($line)['msg']."\n");
   writeDSC("005".$password);
   $loginSuccess = false;
   $lines = readData();
   foreach($lines as $line) {
       $res = readDSC($line);
       echo ("  ".$res['msg']."\n");
       if ($res['code']==505 && $res['msg']=="Logged in") $loginSuccess = true;
   }
   if ($loginSuccess)
      echo ("Login completed sucessfully.\n");
   else
      throw new Exception( "Unable to log in to Envisalink: ".$res['msg']);
}

function breakCode ($startCode) {
   global $enterInstallerMode;
   global $stopTime;
   for ($code = $startCode;$code<10000;$code++) {
	    echo ("Testing code: ".sprintf("%04d",$code)."\n");
	    writeDSC("071".$enterInstallerMode.sprintf("%04d",$code));
        $lastCode = $code;
        $loop = true;
        while ($loop) {  // Loop until receive Invalid access code or an Error message
            $lines = readData();
            foreach ($lines as $line) {
                $res = readDSC($line);
	            if ($res['code']==680) { // Entered installer mode - exit this mode and return
	                echo "*** Installer mode entered using: ".$lastCode." ***\n";
                    $stopTime = microtime(true);
	                sleep(4);
                    echo "Exiting installer mode, please wait couple of seconds to make sure control panel setup is not messed up!\n";
                    for ( $i=0; $i<50; $i++ ) {
                        writeDSC("070#");
		                $results = readData();
                        foreach ($results as $result) {
                            $res = readDSC($result);
                            echo ("  ".$res['msg']."\n");
                            if ($res['code'] == 650) return $lastCode; // System ready, we can safely return.
                        }
                        sleep(4);
                    }
	                return $lastCode;
	            }
                elseif ($res['code']==670) // Invalid access code
                    $loop = false;
	            elseif ($res['code']==502) {
                    $code--;               // Error, repeate check for this code.
                    sleep(1);
                    $loop = false;
                }
	            echo("  ".$res['msg']."\n");
            }
        }
    }
    $stopTime = microtime(true);
    return -$lastCode;
}

function addCheckSum($msg) {
	$checksum=0;
	for ($i=0;$i<strlen($msg);$i++) {
		$checksum+= ord($msg[$i]);
		$checksum %= 256;
	}
    $checksumStr = strtoupper(sprintf("%02x",$checksum));
    return $msg.$checksumStr.chr(13).chr(10);
}

function writeDSC($msg) {
   	global $socket;
	$msg = addCheckSum($msg);
	if( socket_write( $socket, $msg ) === false )
        throw new Exception( sprintf( "Unable to write to socket: %s", socket_strerror( socket_last_error() ) ) );
}

function readData() {
	global $socket;
    $retval = [];
	$read = socket_read( $socket, 1024);
	if( $read == false )
	    throw new Exception( sprintf( "Unable to read from socket: %s", socket_strerror( socket_last_error() ) ) );
    $lines = explode("\n",$read);
    foreach ($lines as $line)
        if (strlen($line)) $retval[] = $line;
    return($retval);
}

function readDSC($read) {
    $retcode = intval(substr($read,0,3));
	$retcodedata=0;
	switch ($retcode) {
		case 500:   $msg="OK"; break;
		case 501:   $msg="Error Command, bad checksum"; break;
		case 502:   $msg="System error: ";
			        $retcodedata = intval(substr($read,3,3));
			        switch ($retcodedata) {
				        case 0: $msg.="No error"; break;
				        case 1: $msg.="Receive buffer overrun"; break;
				        case 2: $msg.="Receive buffer overflow"; break;
				        case 3: $msg.="Transmit buffer overflow"; break;
				        case 10:$msg.="Keybus Transmit Buffer Overrun"; break;
				        case 11:$msg.="Keybus Transmit Time Timeout"; break;
                        case 12:$msg.="Keybus Transmit Mode Timeout"; break;
                        case 13:$msg.="Keybus Transmit Keystring Timeout"; break;
                        case 14:$msg.="Keybus Interface Not Functioning (the TPI cannot communicate with the security system)"; break;
                        case 15:$msg.="Keybus Busy (Attempting to Disarm or Arm with user code)"; break;
                        case 16:$msg.="Keybus Busy – Lockout (The panel is currently in Keypad Lockout – too many disarm attempts)"; break;
                        case 17:$msg.="Keybus Busy – Installers Mode (Panel is in installers mode, most functions are unavailable)"; break;
                        case 18:$msg.="Keybus Busy – General Busy (The requested partition is busy)"; break;
                        case 20:$msg.="APICommandSyntaxError"; break;
                        case 21:$msg.="APICommandPartitionError(RequestedPartitionisoutofbounds)"; break;
                        case 22:$msg.="APICommandNotSupported"; break;
                        case 23:$msg.="APISystemNotArmed(sentinresponsetoadisarmcommand)"; break;
                        case 24:$msg.="APISystemNotReadytoArm(systemiseithernot-secure,inexit-delay,oralreadyarmed)"; break;
                        case 25:$msg.="APICommandInvalidLength"; break;
                        case 26:$msg.="APIUserCodenotRequired"; break;
                        case 27:$msg.="APIInvalidCharactersinCommand(noalphacharactersareallowedexceptforchecksum)"; break;
				        default:$msg.= substr($read,3,3);break;
			        }
			        break;
		case 505:   $retcodedata=intval(substr($read,3,1));
			        switch ($retcodedata) {
				        case 0: $msg="Login failed"; break;
				        case 1: $msg="Logged in"; break;
				        case 2: $msg="Login timeout";break;
				        case 3: $msg="Password request";break;
			        };
			        break;
    	case 609:   $msg="Zone open: ".substr($read,3,strlen($read)-6);break;
		case 610:   $msg="Zone close: ".substr($read,3,strlen($read)-6);break;
        case 650:   $msg="Partition ready: ".substr($read,3,strlen($read)-6);break;
		case 615:   $msg="Timer dump";break;
    	case 510:   $msg= keypadState($read);break;
        case 511:   $msg= keypadFlashState($read);break;
		case 670:   $msg="Invalid access code"; break;
        case 922:   $msg="Installer code required";break;
		case 680: $msg="Installer mode entered"; break;
		default:  $msg="Other: ".substr($read,0,strlen($read)-3);
	}
	$retval['code']= $retcode;
	$retval['data']= $retcodedata;
    $retval['msg']= $msg;
	return $retval;
}

function keypadState ($readData) {
    $state= substr($readData,3,strlen($readData)-6);
    $stateByte= hexdec($state);
    $led="";
    if ($stateByte & 128) $led.=" Backlit ON";
    if ($stateByte & 64) $led.=" Fire ON";
    if ($stateByte & 32) $led.=" Program ON";
    if ($stateByte & 16) $led.=" Trouble ON";
    if ($stateByte & 8) $led.=" Bypass ON";
    if ($stateByte & 4) $led.=" Memory ON";
    if ($stateByte & 2) $led.=" Armed ON";
    if ($stateByte & 1) $led.=" Ready ON";
    if (!strlen($led)) $led="NONE";
    return "Keypad State: ".$led;
}

function keypadFlashState ($readData) {
    $state = substr($readData,3,strlen($readData)-6);
    $stateByte = hexdec($state);
    $led="";
    if ($stateByte & 128) $led.=" Backlit FLASHING";
    if ($stateByte & 64) $led.=" Fire FLASHING";
    if ($stateByte & 32) $led.=" Program FLASHING";
    if ($stateByte & 16) $led.=" Trouble FLASHING";
    if ($stateByte & 8) $led.=" Bypass FLASHING";
    if ($stateByte & 4) $led.=" Memory FLASHING";
    if ($stateByte & 2) $led.=" Armed FLASHING";
    if ($stateByte & 1) $led.=" Ready FLASHING";
    if (!strlen($led)) $led="NONE FLASHING";
    return "Keypad State: ".$led;
}


/* Allow the script to hang around waiting for connections. */
set_time_limit(0);

/* Turn on implicit output flushing so we see what we're getting
 * as it comes in. */
ob_implicit_flush();

echo "Connecting to Envisalink at adress $address...\n";
if (($socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP)) === false)
    echo "socket_create() failed: reason: " . socket_strerror(socket_last_error()) . "\n";
if( !socket_connect( $socket, $address, $port ) )
    throw new Exception( sprintf( "Unable to connect to server %s: %s", $address, socket_strerror( socket_last_error() ) ) ); 
echo ("Connection established successfully.\n");
envisalinkLogin($password);
$startTime = microtime(true);
$code = breakCode($startingCode);
if ($code>=0) echo ("Code found: > $code < Please check it at your panel.\n");
else echo ("Code not found...");
$execTime = $stopTime - $startTime;
$codesTested = abs($code) - $startingCode;
echo (sprintf( "Tested %d combinations in %.2f seconds, %.1f codes per minute. \n",$codesTested, $execTime, $codesTested/$execTime * 60));
socket_close($socket);
?>
