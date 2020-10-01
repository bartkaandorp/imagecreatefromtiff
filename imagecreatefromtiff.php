<?php 

function imagecreatefromtiff($filename) {
	$tiff = new TiffImageClass($filename);
	return $tiff->imagecreatefromtiff();
}

class TiffImageClass {
	public $data;
	
	public $byte_order;
	public $is_little_endian;
	public $tiff_version;
	public $first_ifd_offset;
	public $next_ifd_offset;
	
	public function read_short($offset) {
		if ($this->byte_order == 'MM') return current(unpack('n', substr($this->data, $offset, 2)));
		else return current(unpack('v', substr($this->data, $offset, 2)));
	}
	
	public function read_long($offset) {
		if ($this->byte_order == 'MM') return current(unpack('N', substr($this->data, $offset, 4)));
		else return current(unpack('V', substr($this->data, $offset, 4)));
	}
	
	public function read_bytes($offset, $num) {
		$values = [];
		for ($i = 0; $i < $num; $i++) {
			$values[] = ord($offset + $i);
		}
		return $values;
	}
	public function read_ascii($offset, $num) {
		return substr($this->data, $offset, $num);
	}
	public function read_shorts($offset, $num) {
		$values = [];
		for ($i = 0; $i < $num; $i++) {
			$values[] = $this->read_short($offset + $i * 2);
		}
		return $values;
	}
	public function read_longs($offset, $num) {
		$values = [];
		for ($i = 0; $i < $num; $i++) {
			$values[] = $this->read_long($offset + $i * 4);
		}
		return $values;
	}
	
	
	// Read the 8 byte header of the file
	public function read_header() {
		$this->byte_order = substr($this->data, 0, 2);
		$this->is_little_endian = $this->byte_order == 'II';
		$this->tiff_version = $this->read_short(2);
		$this->first_ifd_offset = $this->read_long(4);
	}
	
	// Read an image file directory
	public function read_image_file_directory($offset) {
		
		$number_of_tags = $this->read_short($offset);
		$tags = [];
		for ($i = 0; $i < $number_of_tags; $i++) {
			$tag_offset = $offset + 2 + $i * 12;
			$tag = [
				'id' => $this->read_short($tag_offset),
				'type' => $this->read_short($tag_offset+ 2),
				'data_count' => $this->read_long($tag_offset + 4),
				'data_offset' => $this->read_long($tag_offset + 8),
				'value' => null,
				'tag_offset' => $tag_offset
			];
			
			// The tag value is stored in the offset field if the total size is less than or equal to 4 bytes
			if ($tag['type'] == 1 && $tag['data_count'] <= 4) $tag['value'] = $this->read_bytes($tag_offset + 8, $tag['data_count']); // Unsigned bytes
			if ($tag['type'] == 2 && $tag['data_count'] <= 4) $tag['value'] = $this->read_ascii($tag_offset + 8, $tag['data_count']); // ASCII string
			if ($tag['type'] == 3 && $tag['data_count'] <= 2) $tag['value'] = $this->read_shorts($tag_offset + 8, $tag['data_count']); // Shorts
			if ($tag['type'] == 4 && $tag['data_count'] <= 1) $tag['value'] = $this->read_longs($tag_offset + 8, $tag['data_count']); // Longs
			
			// Read the values for the strips (273 == StripOffsets, 279 == StripByteCounts)
			if (($tag['id'] == 273 || $tag['id'] == 279) && $tag['value'] === null) {
				if ($tag['type'] == 3) $tag['value'] = $this->read_shorts($tag['data_offset'], $tag['data_count']);
				if ($tag['type'] == 4) $tag['value'] = $this->read_longs($tag['data_offset'], $tag['data_count']);
			}
			
			// Read the values for the bits per sample
			if (($tag['id'] == 258) && $tag['value'] === null) {
				if ($tag['type'] == 3) $tag['value'] = $this->read_shorts($tag['data_offset'], $tag['data_count']);
				if ($tag['type'] == 4) $tag['value'] = $this->read_longs($tag['data_offset'], $tag['data_count']);
			}
			
			$tags[$tag['id']] = $tag;
		}
		$this->next_ifd_offset = $this->read_long($offset + $number_of_tags * 12 + 2);
		
		return $tags;
	}
	
	public function imagecreatefromtiff() {
		
		$this->read_header();
		
		$tags = $this->read_image_file_directory($this->first_ifd_offset);
		
		// Check if the image width and height are set
		if (!empty($tags[256]) && !empty($tags[257])) {
			$image_width = $tags[256]['value'][0];
			$image_height = $tags[257]['value'][0];
		}
		
		// Create an empty image and fill with white
		$image = imagecreatetruecolor($image_width, $image_height);
		imagefilledrectangle($image, 0, 0, $image_width - 1, $image_height - 1, 0xFFFFFF);
		
		// Check if this is a strips image
		if (!empty($tags[273]) && !empty($tags[279]) && !empty($tags[278])) {
			$rows_per_strip = $tags[278]['value'][0];
			$number_of_strips = floor(($image_height * ($rows_per_strip - 1)) / $rows_per_strip);
			
			$compression = $tags[259]['value'][0];
			$strip_offsets = $tags[273]['value'];
			$strip_byte_counts = $tags[279]['value'];
			$planar_configuration = $tags[284]['value'][0];
			$predictor_decoding = !empty($tags[317]) && $tags[317]['value'][0] == 2 ? true : false;
			$bits_per_sample = $tags[258]['value'];
			$samples_per_pixel = $tags[277]['value'][0];
			$bits_per_pixel = 0;
			for ($i = 0; $i < count($bits_per_sample); $i++) {
				$bits_per_pixel += $bits_per_sample[$i];
			}
			
			// Only support full bytes for now
			$bytes_per_pixel = (int)floor($bits_per_pixel / 8);
			$bytes_per_sample = (int)floor($bits_per_sample[0] / 8);
			
			$timer = microtime(true);
			
			// Walk all the strips
			$y = 0;
			$buffer_offset = 0;
			foreach ($strip_offsets as $i => $offset) {
				
				// Get the buffer data from the file
				$buffer = substr($this->data, $offset, $strip_byte_counts[$i]);
				$decoded = false;
				
				// Decode LZW
				if ($compression == 5) {
					$decoded = $this->lzw_decode($buffer);
				}
				// Zip compression
				else if ($compression == 8) {
					$decoded = gzuncompress($buffer);
				}
				
				if (!$decoded) break;
				$decoded_length = strlen($decoded);
				
				// Walk the decoded data and add the pixels to the image
				$previous_pixel = false;
				$x = 0;
				for ($i = 0; $i < $decoded_length; $i += $bytes_per_pixel) {
					
					$pixel = [];
					
					// Get the pixel values
					$offset = 0;
					foreach ($bits_per_sample as $bits) {
						if ($bits == 8) {
							$pixel[] = ord($decoded[$i + $offset]);
							$offset += 1;
						}
						else if ($bits == 16) {
							if (!$this->is_little_endian) $pixel[] = floor((ord($decoded[$i + $offset]) << 8 | ord($decoded[$i + $offset + 1])) / 256);
							else $pixel[] = floor((ord($decoded[$i + $offset]) | ord($decoded[$i + $offset + 1]) << 8) / 256);
							$offset += 2;
						}
					}
					
					// Add the values of the previous pixel if predictor encoding is set
					if ($predictor_decoding && $x) {
						foreach ($bits_per_sample as $c => $bits) {
							$pixel[$c] = ($previous_pixel[$c] + $pixel[$c]) % 256;
						}
					}
					$previous_pixel = $pixel;
					
					// Translate 16 bit colors to 8 bit
					foreach ($bits_per_sample as $c => $bits) {
						if ($bits == 16) $pixel[$c] = (int)floor($pixel[$c] / 256);
					}
					
					// Create the color
					$color = $pixel[0] << 16 | $pixel[1] << 8 | $pixel[2];
					if (isset($pixel[3])) $color = $color | (255 - $pixel[3]) << 24;
					
					// Draw the pixel
					imagesetpixel($image, $x, $y, $color);
					
					// Increment the x and y pos
					$x++;
					if ($x >= $image_width) {
						$y++;
						$x = 0;
					}
				}
				
				$buffer_offset += strlen($decoded);
			}
			
		}
		
		return $image;
	}
	
	public function lzw_decode($buffer) {
		$decoded = '';
			
		$bit_pos = 0;
		$bits_per_code = 9;
		$max_code = (1 << $bits_per_code) - 2;
		$new_index = 258;
		$dictionary = [];
		for ($c = 0; $c < 256; $c++) $dictionary[$c] = chr($c);
		$word = '';
		$outstring = '';
		$old_code = '';
		
		for ($i = 0; $i < strlen($buffer); $i++) {
			$code = $this->get_bits_in_stream($buffer, $bit_pos, $bits_per_code);
			$bit_pos += $bits_per_code;
			
			if ($code > $new_index) {
				//var_dump('Error code ' . $code . ' not in index');
				break;
			}
			
			if ($code == 256) {
				$bits_per_code = 9;
				$max_code = (1 << $bits_per_code) - 2;
				$new_index = 258;
				$dictionary = [];
				for ($c = 0; $c < 256; $c++) $dictionary[$c] = chr($c);
				
				$code = $this->get_bits_in_stream($buffer, $bit_pos, $bits_per_code);
				$bit_pos += $bits_per_code;
				
				if ($code == 257) {
					//var_dump('End of information reached');
					break;
				}
				
				$decoded .= $dictionary[$code];
				$old_code = $code;
			}
			else if ($code == 257) {
				//var_dump('End of information reached');
				break;
			}
			else if ($code < 4096) {
				
				if (isset($dictionary[$code])) {
					$decoded .= $dictionary[$code];
					$dictionary[$new_index++] = $dictionary[$old_code] . $dictionary[$code][0];
					$old_code = $code;
				}
				else {
					$outstring = $dictionary[$old_code] . $dictionary[$old_code][0];
					$decoded .= $outstring;
					$dictionary[$new_index++] = $outstring;
					$old_code = $code;
				}
				
				if ($new_index > $max_code) {
					$bits_per_code++;
					$max_code = (1 << $bits_per_code) - 2;
				}
			}
		}
		
		return $decoded;
	}
	
	public function get_bits_in_stream(&$buffer, $bit_pos, $bits_per_code) {
		
		$result = 0;
		
		
		$byte_pos = (int)floor($bit_pos / 8);
		
		while (($byte_pos * 8) < $bit_pos + $bits_per_code) {
			$pos_in_byte = ($bit_pos - $byte_pos * 8) % 8;
			
			// Mask the byte to remove the first bits from the byte
			$mask = 255;
			if ($pos_in_byte > 0) $mask = 255 >> $pos_in_byte;
			
			// Get the byte from the buffer and apply the mask
			$byte = ord($buffer[$byte_pos]) & $mask;
			
			// Shift the byte data to the left of the result
			if (($shift_to_left = ($bit_pos + $bits_per_code) - (($byte_pos + 1) * 8)) > 0) {
				$result = $result | ($byte << $shift_to_left);
			}
			// Shift the byte data to the right
			else if (($shift_to_right = ($bit_pos + $bits_per_code) - $byte_pos * 8) < 8) {
				$result = $result | ($byte >> (8 - $shift_to_right));
			}
			// Use the full byte
			else {
				$result = $result | $byte;
			}
			
			$byte_pos++;
		}
		
		return $result;
	}
	
	public function __construct($file = false) {
		if ($file) $this->data = file_get_contents($file);
	}
}

?>