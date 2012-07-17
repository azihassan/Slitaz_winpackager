<?php
define('USAGE', 'Usage : php '.$argv[0].' package_name --package package_name [--path '.__DIR__.'] [--dependencies] [--overwrite]'.PHP_EOL);

if($argc == 1)
{
	echo USAGE;
	exit;
}

try
{
	list($package_name, $target_dir, $dependencies, $help, $overwrite, $no_cache) = parse_arguments($argv);
	if($help === TRUE)
	{
		echo USAGE;
		exit;
	}
	
	echo 'Extracting the links...'.PHP_EOL;
	$src = get_source('http://pkgs.slitaz.org/search.sh', $package_name, $dependencies, $no_cache);
	$links = extract_links($src, $dependencies ? NULL : $package_name);
	
	if(empty($links))
	{
		echo 'No links for the package were found.'.PHP_EOL;
		exit;
	}
	foreach($links as $l)
	{
		try
		{
			download_file($l, $target_dir, $overwrite);
		}
		catch(Exception $e)
		{
			echo "\r".'Downloading '.basename($l).' : '.$e->getMessage().PHP_EOL.PHP_EOL;
			continue;
		}
	}
}
catch(Exception $e)
{
	echo $e->getMessage();
	exit;
}


function extract_links($string, $package_name = NULL)
{
	$dom = new DOMDocument();
	$links = array();
	
	$dom->recover = TRUE;
	$dom->strictErrorChecking = FALSE;
	@$dom->loadHTML($string);
	
	$xpath = new DOMXPath($dom);
	$query = $package_name == NULL ? '//pre[1]/a/@href' : "//pre[1]/a[text()='$package_name']/@href";
	
	foreach($xpath->query($query) as $link)
	{
		if(pathinfo($link->nodeValue, PATHINFO_EXTENSION) == 'tazpkg')
		{
			$links[] = $link->nodeValue;
		}
	}
	
	return $links;
}

function get_source($url, $package_name, $dependencies = FALSE, $no_cache = FALSE)
{
	$ch = curl_init();
	$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.$package_name.($dependencies ? '_dep' : '').'.tmp';
	$query = $url.'?';
	$query .= $dependencies ? 'depends='.$package_name : 'package='.$package_name;
	$query .= '&version=s';
	
	if(is_readable($tmp) && $no_cache === FALSE)
	{
		curl_close($ch);
		return file_get_contents($tmp);
	}
	
	curl_setopt($ch, CURLOPT_URL, $query);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
	curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; rv:13.0) Gecko/20100101 Firefox/13.0.1');
	
	$result = curl_exec($ch);
	if($result === FALSE)
	{
		$err = curl_error($ch);
		curl_close($ch);
		throw new Exception('Failed to retrieve the source : '.$err);
	}
	curl_close($ch);
	file_put_contents($tmp, $result);
	return $result;
}

function download_file($link, $target_dir, $overwrite)
{
	$ch = curl_init();
	$path = $target_dir.DIRECTORY_SEPARATOR.basename($link);
	$package_name = basename($link);
	
	if(file_exists($path) && !$overwrite)
	{
		throw new Exception('The file already exists.');
	}
	
	if(($f = fopen($path, 'wb')) === FALSE)
	{
		throw new Exception($path.' is not writable.');
	}
	
	curl_setopt($ch, CURLOPT_URL, $link);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
	curl_setopt($ch, CURLOPT_NOPROGRESS, FALSE);
	
	curl_setopt($ch, CURLOPT_FILE, $f);
	curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($dl_size, $dled, $up_size, $uped) use($package_name)
	{
		static $calls = 0;
		if(++$calls % 3 != 0)
		{
			/* The rest of the code will be executed only 1/3 times
			 * This fixes a bug where the progress was displayed three times
			 * when it goes near 100%
			 * */
			return;
		}
		
		if($dl_size != 0)
		{
			$percentage = round($dled / $dl_size, 2) * 100;
			$human_size = round($dl_size / 1024, 2).' kBs';
			
			echo 'Downloading '.$package_name.' ['.$human_size.']...'."\t".$percentage.'%';
			if($dled < $dl_size)
			{
				echo "\r";
			}
			else
			{
				echo PHP_EOL;
			}
		}
	});
	
	$result = curl_exec($ch);
	$err = curl_error($ch);
	fclose($f);
	curl_close($ch);
	
	if($result === FALSE)
	{
		throw new Exception('Failed to download the file : '.$err);
	}
	
	return $result;
}

function parse_arguments($argv)
{
	if($help = in_array('--help', $argv))
	{
		return array(NULL, NULL, NULL, TRUE, NULL);
	}
	
	if(($key = array_search('--path', $argv)) !== FALSE)
	{
		$target_dir = $argv[$key + 1];
		if(!is_writable($target_dir) && mkdir($target_dir) === FALSE)
		{
			throw new Exception($target_dir.' is not writable. Try again with another path or leave it empty for the current path.');
		}
	}
	else
	{
		$target_dir = __DIR__;
	}
	
	if(($key = array_search('--package', $argv)) !== FALSE)
	{
		$package_name = $argv[$key + 1];
	}
	else
	{
		throw new Exception('Argument --package is missing. Type --help for usage.');
	}
	
	$dependencies = in_array('--dependencies', $argv);
	$overwrite = in_array('--overwrite', $argv);
	$no_cache = in_array('--nocache', $argv);
	
	return array($package_name, $target_dir, $dependencies, $help, $overwrite, $no_cache);
}
