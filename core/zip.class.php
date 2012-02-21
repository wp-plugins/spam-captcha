<?php
/*
Core SedLex Plugin
VersionInclude : 3.0
*/ 

require_once (ABSPATH . 'wp-admin/includes/class-pclzip.php');

/** =*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*=*
* This PHP class creates zip file (multipart if needed)
* It requires the gzcompress function. Otherwise, a fatal error will be raised
* For instance : 
* <code>$z = new SL_Zip;<br/>$z -> addFile("/www/test/File.txt","/www/test/","/newroot/");<br/>$z -> addDir("/www/test/Folder","/www/test/","/newroot/") ; <br/>$z -> createZip("/pathToZip/backup.zip",1048576);</code>
*/
if (!class_exists("SL_Zip")) {
	class SL_Zip {
		var $filelist = array();
		var $starttime =0 ; 
		
		function SL_Zip() {
			$this->starttime = microtime(true) ; 
			if (!@function_exists('gzcompress')) {
				die(sprintf(__('Error: %s function is not found', 'SL_framework'), "<code>gzcompress()</code>"));
			}
		}
		
		/** ====================================================================================================================================================
		* Return the progression ratio
		* 
		* @param $file the zip file that is being created
		* @return string the progress nb_file_included/nb_file
		*/
		
		function progress($file) {
			
			if (is_file($file.".tmp")) {
				// We retrieve the process
				$content = @file_get_contents($file.".tmp") ; 
				list($data_segments_len, $nbentry, $pathToReturn, $disk_number, $filelist, $nb_file_not_included_due_to_filesize, $file_headers_len) = unserialize($content) ; 
				return $nbentry."/".(count($filelist)+$nbentry) ; 
			} 
			return "" ; 
		}	
		
		/** ====================================================================================================================================================
		* Add files to the archive
		* 
		* @param string $filename the path of the file to add
		* @param string $remove the part of the path to remove
		* @param string $add the part of the path to add
		* @return void
		*/
		
		function addFile($filename, $remove="", $add="") {
			if(is_file($filename)) {
				$this->filelist[] = array(str_replace('\\', '/', $filename), $remove, $add) ;
			} else {
				// Nothing
			}
		}
		
		
		/** ====================================================================================================================================================
		* Add directory to the archive (reccursively)
		* 
		* @param string $dirname the path of the folder to add
		* @param string $remove the part of the path to remove
		* @param string $add the part of the path to add
		* @param array $exclu a list of folder that are no to be included in the zip file
		* @return void
		*/
		
		function addDir($dirname, $remove="", $add="", $exclu=array()) {
			if ($handle = opendir($dirname)) { 
				while (false !== ($filename = readdir($handle))) { 
					// We check if exclu
					$exclu_folder = false ; 
					foreach($exclu as $e) {
						$path = str_replace("//", "/", $dirname . '/' . $filename) ; 
						if (($e==$path)||($e==$path."/")) {
							$exclu_folder=true ; 
						}
					}
					// On recursive
					if ($filename != "." && $filename != ".." && !$exclu_folder)  {
						if (is_file($dirname . '/' . $filename)) {
							$this->addFile($dirname . '/' . $filename, $remove, $add);
						} 
						if (is_dir($dirname . '/' . $filename)) {
							$this->addDir($dirname . '/' . $filename, $remove, $add, $exclu);
						}
					}
				} 
				closedir($handle); 
			} else {
				//Nothing
			}
		}
		
		/** ====================================================================================================================================================
		* Tells whether a zip file is being created or not
		* 
		* @param $path the path in which the zip should be created
		* @return array the 'step' could be 'in progress' (a process is still running), 'nothing' (no zip is being zipped) or 'to be completed' (and the 'name_zip' will be the name of the zip file being zipped) or 'error' (and the 'error' will display the error messgae)
		*/
		
		function is_inProgress($path) {
			if (is_file($path."/in_progress")) {
				$timestart = @file_get_contents($path."/in_progress")  ;
				if ($timestart===FALSE) {
					return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be read. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
				}
				$timeprocess = time() - (int)$timestart ; 
				// We ensure that the process has not been started a too long time ago
				if ($timeprocess<200) {
					return array("step"=>"in progress", "for"=>$timeprocess) ; 
				} else {
					if (!Utils::rm_rec($path."/in_progress")) {
						return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
					}
				}
			} 
			
			// We search for a tmp file
			$files = @scandir($path) ;
			if ($files===FALSE) {
				return array("step"=>"error", "error"=>sprintf(__('The folder %s cannot be opened. You should have a problem with folder permissions or security restrictions.', 'SL_framework'),"<code>".$path."</code>")) ; 
			}
			foreach ($files as $f) {
				if (preg_match("/zip[.]tmp$/i", $f)) {
					$name_file = str_replace(".zip.tmp", ".zip",$f) ; 
					return array("step"=>"to be completed", 'name_zip' => $name_file) ; 
				} 
			}
			return array("step"=>"nothing") ; 
		}	
		
		/** ====================================================================================================================================================
		* Create the archive and split it if necessary
		* 
		* @param string $splitfilename the path of the zip file to create
		* @param integer $chunk_size the maximum size of the archive
		* @param integer $maxExecutionTime the maximum execution time in second (if this time is exceeded, the function will return false. You just have to relaunch this function to complete the zip from where it has stopped)
		* @param integer $maxExecutionTime the maximum memory allocated by the process (in bytes)
		* @return array with the name of the file (or 'finished' => false if an error occured see 'error' for the error message)
		*/
		
		function createZip($splitfilename, $chunk_size=1000000000000000, $maxExecutionTime=150, $maxAllocatedMemory=4000000) {
			
			// Init variables
			//---------------------
			
			$zipfile_comment = "Compressed/Splitted by the SL framework (SedLex)";
			
			$path = str_replace(basename ($splitfilename), "", $splitfilename) ; 
			
			$pathToReturn = array() ; 
			
			$disk_number = 1 ; 
			$split_signature = "\x50\x4b\x07\x08";
			$nbentry = 0 ; 
			$file_headers = "" ; 
			$data_segments = "" ; 
			$data_segments_len = 4 ; // because there is the split signature
			$file_headers_len = 0 ; 
			$nb_file_not_included_due_to_filesize = 0 ; 
			
			//  We check whether a process is running
			//----------------------------------------------

			if (is_file(dirname($splitfilename)."/in_progress")) {
				$timestart = @file_get_contents(dirname($splitfilename)."/in_progress")  ;
				
				// We cannot read the lock file
				if ($timestart===FALSE) {
					if (!Utils::rm_rec($path."/in_progress")) {
						return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
					}
					return array('finished'=>false, "error"=>sprintf(__('The file %s cannot be read. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".dirname($splitfilename)."/in_progress</code>")) ; 
				}
				$timeprocess = time() - (int)$timestart ; 
				// We ensure that the process has not been started a too long time ago
				if ($timeprocess<200) {
					if (!Utils::rm_rec($path."/in_progress")) {
						return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
					}
					return array('finished'=>false, 'error' => sprintf(__("An other process is still running (it runs for %s seconds)", "SL_framework"), $timeprocess)) ; 
				} else {
					// We create a file with the time inside to indicate that this process is doing something
					$r = @file_put_contents(dirname($splitfilename)."/in_progress", time()) ; 
				}
			}
			
			
			//  We create a lock file
			//----------------------------------------------

			$r = @file_put_contents(dirname($splitfilename)."/in_progress", time()) ; 
			if ($r===FALSE) {
				if (!Utils::rm_rec($path."/in_progress")) {
					return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
				}
				return array('finished'=>false, "error"=>sprintf(__('The file %s cannot be modified/created. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".dirname($splitfilename)."/in_progress</code>")) ; 
			}
					
			//  We retrieve old saved param
			//      if the .tmp file exists, it means that we have to restart the zip process where it stopped
			//----------------------------------------------

			if (is_file($splitfilename.".tmp")) {
				// We retrieve the process
				$content = @file_get_contents($splitfilename.".tmp") ; 
				
				if ($content===FALSE) {
					if (!Utils::rm_rec($path."/in_progress")) {
						return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
					}
					return array('finished'=>false, "error"=>sprintf(__('The file %s cannot be read. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$splitfilename.".tmp</code>")) ; 
				}
				
				list($data_segments_len, $nbentry, $pathToReturn, $disk_number, $this->filelist, $nb_file_not_included_due_to_filesize, $file_headers_len) = unserialize($content) ; 
			} 
			
			if (!is_file($splitfilename.".file_headers.tmp")) {
				$r = @file_put_contents($path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number) ,$split_signature) ; 
				$pathToReturn[] = $path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number) ;
				if ($r===FALSE) {
					if (!Utils::rm_rec($path."/in_progress")) {
						return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
					}
					return array('finished'=>false, "error"=>sprintf(__('The file %s cannot be created. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number)."</code>")) ; 
				}
			}
				
			
			//  The creation of the zip begin
			//----------------------------------------------

			foreach($this->filelist as $k => $filename_array) {
				$add_t = $filename_array[2] ; 
				$remove_t = $filename_array[1] ; 
				$filename = $filename_array[0] ; 
				
				//  If the time limit / memory limit exceed, we save into temp files
				//----------------------------------------------
				
				$nowtime = microtime(true) ; 
				if ($maxExecutionTime!=0) {
					if ($nowtime - $this->starttime > $maxExecutionTime){
						// We remove the files already inserted in the zip
						$this->filelist =  array_slice($this->filelist,$k);
						// We save the content on the disk
						
						$r = @file_put_contents($splitfilename.".tmp" ,serialize(array($data_segments_len, $nbentry, $pathToReturn, $disk_number, $this->filelist, $nb_file_not_included_due_to_filesize, $file_headers_len))) ; 
						if ($r===FALSE) {
							if (!Utils::rm_rec($path."/in_progress")) {
								return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
							}
							return array('finished'=>false, "error"=>sprintf(__('The file %s cannot be modified/created. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$splitfilename.".tmp</code>")) ; 
						}
						// we inform that the process is finished
						if (!Utils::rm_rec($path."/in_progress")) {
							return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
						}
						return  array('finished'=>false, 'nb_to_finished' => count($this->filelist), 'nb_finished' => ($nbentry), 'nb_not_included'=>$nb_file_not_included_due_to_filesize) ; 
					}
				}
				
				
				//  Check if the file to be inserted in the zip file still exists
				//----------------------------------------------

				if (!is_file($filename)) {
					continue ; 
				}
				
				// Check the length of the file
				if (filesize($filename)>$maxAllocatedMemory) {
					$nb_file_not_included_due_to_filesize ++ ; 
					continue ; 
				}
				
				//  Compress
				//----------------------------------------------

				$nbentry ++ ; 
				$file_headers = "" ; 
				
				//Get the data
				$filedata = @file_get_contents($filename);
				if ($filedata===FALSE) {
					if (!Utils::rm_rec($path."/in_progress")) {
						return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
					}
					return array('finished'=>false, "error"=>sprintf(__('The file %s cannot be read. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$filename."</code>")) ; 
				}
				
				//Compressing data
				$c_data = @gzcompress($filedata);
				if ($c_data===FALSE) {
					if (!Utils::rm_rec($path."/in_progress")) {
						return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
					}
					return array('finished'=>false, "error"=>sprintf(__('The file %s cannot be compressed.', 'SL_framework'),"<code>".$filename."</code>")) ; 
				}
				$compressed_filedata = substr(substr($c_data, 0, strlen($c_data) - 4), 2); // fix crc bug
								
				// Get the time
				clearstatcache();
				$filetime = @filectime($filename);
				if ($filetime == 0) { 
					$timearray = getdate() ;
				} else { 
					$timearray = getdate($filetime) ; 
				}
				if ($timearray['year'] < 1980) {
					$timearray['year']    = 1980;
					$timearray['mon']     = 1;
					$timearray['mday']    = 1;
					$timearray['hours']   = 0;
					$timearray['minutes'] = 0;
					$timearray['seconds'] = 0;
				} 
				$dostime = (($timearray['year'] - 1980) << 25) | ($timearray['mon'] << 21) | ($timearray['mday'] << 16) | ($timearray['hours'] << 11) | ($timearray['minutes'] << 5) | ($timearray['seconds'] >> 1);
				$dtime    = dechex($dostime);
				$hexdtime = '\x' . $dtime[6] . $dtime[7] . '\x' . $dtime[4] . $dtime[5];
				$hexddate = '\x' . $dtime[2] . $dtime[3]. '\x' . $dtime[0] . $dtime[1];
				eval('$hexdtime = "' . $hexdtime . '";');
				eval('$hexddate = "' . $hexddate . '";');
				$last_mod_file_time = $hexdtime;
				$last_mod_file_date = $hexddate;
							
				//Set Local File Header
				$newfilename = str_replace("//", "/", $add_t.str_replace(str_replace("\\", "/", $remove_t), "", str_replace("\\", "/", $filename))) ; 
				if (substr($newfilename, 0, 1)=="/") {
					$newfilename = substr($newfilename, 1) ; 
				}
				
				/*
				 A.  Local file header:
					local file header signature     4 bytes  (0x04034b50)
					version needed to extract       2 bytes
					general purpose bit flag        2 bytes
					compression method              2 bytes
					last mod file time              2 bytes
					last mod file date              2 bytes
					crc-32                          4 bytes
					compressed size                 4 bytes
					uncompressed size               4 bytes
					file name length                2 bytes
					extra field length              2 bytes
					file name 						(variable size)
					extra field 					(variable size)
				*/
				
				$local_file_header  = "\x50\x4b\x03\x04";						// 4 bytes  (0x04034b50) local_file_header_signature
				$local_file_header .= "\x14\x00"; 								// 2 bytes version_needed_to_extract
				$local_file_header .= "\x00\x00";  								// 2 bytes general_purpose_bit_flag
				$local_file_header .= "\x08\x00";  								// 2 bytes compression_method
				$local_file_header .= $last_mod_file_time ;						// 2 bytes last mod file time
				$local_file_header .= $last_mod_file_date ;						// 2 bytes last mod file time
				$local_file_header .= pack('V', crc32($filedata)); 				// 4 bytes crc_32
				$local_file_header .= pack('V', strlen($compressed_filedata));	// 4 bytes compressed_size
				$local_file_header .= pack('V', strlen($filedata));				// 4 bytes uncompressed_size
				$local_file_header .= pack('v', strlen($newfilename));			// 2 bytes filename_length
				$local_file_header .= pack('v', 0);  							// 2 bytes extra_field_length
				$local_file_header .= $newfilename  ; 							// variable size filename
				$local_file_header .= ""  ;  									// variable size extra fields 
			
				// We add the local header in the zip files
				if (strlen($local_file_header) + filesize($path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number))<=$chunk_size) {
					$r = @file_put_contents($path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number) ,$local_file_header, FILE_APPEND) ; 
					if ($r===FALSE) {
						if (!Utils::rm_rec($path."/in_progress")) {
							return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
						}
						return array('finished'=>false, "error"=>sprintf(__('The file %s cannot be modified/created. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number)."</code>")) ; 
					}	
				// If the local header will be split, we create a new disk
				} else {
					$disk_number ++ ; 
					$pathToReturn[] = $path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number) ;
					$r = @file_put_contents($path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number) ,$local_file_header) ; 
					if ($r===FALSE) {
						if (!Utils::rm_rec($path."/in_progress")) {
							return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
						}
						return array('finished'=>false, "error"=>sprintf(__('The file %s cannot be modified/created. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number)."</code>")) ; 
					}	
				}
				$disk_number_of_local_header = $disk_number ;
				
				/* 
				 B.  File data
					  Immediately following the local header for a file
					  is the compressed or stored data for the file. 
					  The series of [local file header][file data][data
					  descriptor] repeats for each file in the .ZIP archive. 
				  C.  Data descriptor:
					  crc-32                          4 bytes
					  compressed size                 4 bytes
					  uncompressed size               4 bytes
				*/
				
				//Set Data Descriptor
				
				$data_descriptor  = pack('V', crc32($filedata)); 				// 4 bytes crc_32
				$data_descriptor .= pack('V', strlen($compressed_filedata));	// 4 bytes compressed_size
				$data_descriptor .= pack('V', strlen($filedata));				// 4 bytes uncompressed_size
								
				// We add the compressed file in the zip files
				if (strlen($compressed_filedata) +strlen( $data_descriptor ) + filesize($path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number))<=$chunk_size) {
					$r = @file_put_contents($path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number) ,$compressed_filedata. $data_descriptor, FILE_APPEND) ; 
					if ($r===FALSE) {
						if (!Utils::rm_rec($path."/in_progress")) {
							return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
						}
						return array('finished'=>false, "error"=>sprintf(__('The file %s cannot be modified/created. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number)."</code>")) ; 
					}	
				// If the compressed file will be split, we create a new disk
				} else {
					$part1 = substr($compressed_filedata . $data_descriptor, 0, $chunk_size - filesize($path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number))) ; 
					$part2 = substr($compressed_filedata . $data_descriptor, $chunk_size - filesize($path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number))) ; 
					$r = @file_put_contents($path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number) ,$part1, FILE_APPEND) ; 
					if ($r===FALSE) {
						if (!Utils::rm_rec($path."/in_progress")) {
							return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
						}
						return array('finished'=>false, "error"=>sprintf(__('The file %s cannot be modified/created. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number)."</code>")) ; 
					}	
					$disk_number ++ ; 
					$pathToReturn[] = $path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number) ;
					$r = @file_put_contents($path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number) ,$part2) ; 
					if ($r===FALSE) {
						if (!Utils::rm_rec($path."/in_progress")) {
							return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
						}
						return array('finished'=>false, "error"=>sprintf(__('The file %s cannot be modified/created. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path . basename ($splitfilename,".zip") . ".z" . sprintf("%02d",$disk_number)."</code>")) ; 
					}	
				}
				/*
				  F.  Central directory structure:
					  [file header 1]
					  ...
					  [file header n]
					  [digital signature] 
				
					  File header:
						central file header signature   4 bytes  (0x02014b50)
						version made by                 2 bytes
						version needed to extract       2 bytes
						general purpose bit flag        2 bytes
						compression method              2 bytes
						last mod file time              2 bytes
						last mod file date              2 bytes
						crc-32                          4 bytes
						compressed size                 4 bytes
						uncompressed size               4 bytes
						file name length                2 bytes
						extra field length              2 bytes
						file comment length             2 bytes
						disk number start               2 bytes
						internal file attributes        2 bytes
						external file attributes        4 bytes
						relative offset of local header 4 bytes
						file name 						(variable size)
						extra field 					(variable size)
						file comment 					(variable size)
				
					  Digital signature:
						header signature                4 bytes  (0x05054b50)
						size of data                    2 bytes
						signature data 					(variable size)
				*/
				
				//Set central File Header
				$central_file_header  = "\x50\x4b\x01\x02";							// 4 bytes (0x02014b50) central file header signature
				$central_file_header .= pack('v', 0);  								// 2 bytes version made by
				$central_file_header .= "\x14\x00"; 								// 2 bytes version needed to extract
				$central_file_header .= "\x00\x00";  								// 2 bytes general_purpose_bit_flag
				$central_file_header .= "\x08\x00";  								// 2 bytes compression_method
				$central_file_header .= $last_mod_file_time ;						// 2 bytes last mod file time
				$central_file_header .= $last_mod_file_date;						// 2 bytes last mod file time
				$central_file_header .= pack('V', crc32($filedata)); 				// 4 bytes crc_32
				$central_file_header .= pack('V', strlen($compressed_filedata));	// 4 bytes compressed_size
				$central_file_header .= pack('V', strlen($filedata));				// 4 bytes uncompressed_size
				$central_file_header .= pack('v', strlen($newfilename));			// 2 bytes filename_length
				$central_file_header .= pack('v', 0);  								// 2 bytes extra_field_length
				$central_file_header .= pack('v', 0); 								// 2 bytes  comment length
				$central_file_header .= pack('v', $disk_number_of_local_header-1); 	// 2 bytes disk number start
				$central_file_header .= pack('v', 0) ; 								// 2 bytes internal file attribute
				$central_file_header .= pack('V', 32) ; 							// 4 bytes external file attribute
				$central_file_header .= pack('V', $data_segments_len%$chunk_size);	// 4 bytes relative offset of local header
				$central_file_header .= $newfilename  ; 							// variable size filename
				$central_file_header .= ""  ;  										// variable size extra fields 
				$central_file_header .= "" ; 										// variable size file comment
				
				$data_segments_len += strlen($local_file_header)+strlen($compressed_filedata)+strlen($data_descriptor); 
				$file_headers_len += strlen($central_file_header) ; 
				
				$r = @file_put_contents($splitfilename.".file_headers.tmp" ,$central_file_header, FILE_APPEND) ; 
				if ($r===FALSE) {
					if (!Utils::rm_rec($path."/in_progress")) {
						return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
					}
					return array('finished'=>false, "error"=>sprintf(__('The file %s cannot be modified/created. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$splitfilename.".file_headers.tmp</code>")) ; 
				}			
			}
			
			//  Finalization
			//----------------------------------------------	
			/*
			 I.  End of central directory record:
				end of central dir signature    												4 bytes  (0x06054b50)
				number of this disk            		 											2 bytes
				number of the disk with the start of the central directory  					2 bytes
				total number of entries in the central directory on this disk  					2 bytes
				total number of entries in the central directory           						2 bytes
				size of the central directory  					 								4 bytes
				offset of start of central directory with respect to the starting disk number   4 bytes
				.ZIP file comment length        												2 bytes
				.ZIP file comment       														(variable size)
			*/
						
			// We finalize	
			$end_central_dir_record  = "\x50\x4b\x05\x06";					// 4 bytes  (0x06054b50)
			$end_central_dir_record .= pack('v', $disk_number);				// 2 bytes number of this disk    
			$end_central_dir_record .= pack('v', $disk_number);				// 2 bytes number of the disk with the start of the central directory
			$end_central_dir_record .= pack('v', $nbentry);					// 2 bytes total number of entries in the central directory on this disk 
			$end_central_dir_record .= pack('v', $nbentry);					// 2 bytes total number of entries in the central directory  
			$end_central_dir_record .= pack('V', $file_headers_len);  		// 4 bytes size of the central directory  
			$end_central_dir_record .= pack('V', 0); 						// 4 bytes offset of start of central directory with respect to the starting disk number
			$end_central_dir_record .= pack('v', strlen($zipfile_comment)); // 2 bytes .ZIP file comment length    
			$end_central_dir_record .= $zipfile_comment; 					// variable size .ZIP file comment     
					
			// We complete the data segments file
			$r = @file_put_contents($splitfilename.".file_headers.tmp" , $end_central_dir_record, FILE_APPEND) ; 
			if ($r===FALSE) {
				if (!Utils::rm_rec($path."/in_progress")) {
					return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
				}
				return array('finished'=>false, "error"=>sprintf(__('The file %s cannot be modified/created. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$splitfilename.".file_headers.tmp"."</code>")) ; 
			}
			// rename the file
			$r = @rename($splitfilename.".file_headers.tmp" , $splitfilename) ; 
			$pathToReturn[] = $splitfilename ;

			if ($r===FALSE) {
				if (!Utils::rm_rec($path."/in_progress")) {
					return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$path."/in_progress</code>")) ; 
				}
				return array('finished'=>false, "error"=>sprintf(__('The file %s cannot be renamed. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$splitfilename.".file_headers.tmp"."</code>")) ; 
			}
			
			if (!Utils::rm_rec($splitfilename.".tmp")) {
				return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".$splitfilename.".tmp</code>")) ; 
			}
			// we inform that the process is finished
			if (!Utils::rm_rec(dirname($splitfilename)."/in_progress")) {
				return array("step"=>"error", "error"=>sprintf(__('The file %s cannot be deleted. You should have a problem with file permissions or security restrictions.', 'SL_framework'),"<code>".dirname($splitfilename)."/in_progress</code>")) ; 
			}
			return array('finished'=>true, 'nb_finished'=>$nbentry,'nb_to_finished'=>0, 'nb_not_included'=>$nb_file_not_included_due_to_filesize, 'nb_files'=>$nbentry , 'path'=>$pathToReturn) ; 
		}
	} 
}


?>