# imagecreatefromtiff
This is a function/class to load tiff images into a GD image, written in PHP. Because PHP is not made for this kind of stuff, it will be pretty slow on larger images. For small images (< 1000px width/height) it will be okish.

If possible you should use something like the Imagick PECL extension instead of this.

# Supported options
Tiff has a lot of different options and not everything is supported. Most tiff images are RGB with 8 bit channels, interleaved pixel order and LZW compression (which are the default Photoshop settings) so it will work with that and not much else.

What is supported:
- RGB color with 8 or 16 bit channels
- LZW and ZIP compression
- Interleaved pixel order

What is not supported:
- Other color settings (Grayscale, CMYK) (should be pretty easy to add)
- Other number of bits per channel (4, 32) (should be pretty easy to add)
- Other types of compression (JPEG, Packbits, Huffman)
- Per channel pixel order (should be pretty easy to add)
- Loading images other than the first one in the Tiff file (you can load the rest with the class)
- Tiled images