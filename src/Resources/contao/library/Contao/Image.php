<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2014 Leo Feyer
 *
 * @package Library
 * @link    https://contao.org
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */

namespace Contao;


/**
 * Resizes images
 *
 * The class resizes images and stores them in the assets/images folder.
 *
 * The following resize modes are supported:
 *
 * * Proportional:  proportional resize
 * * Fit-the-box:   proportional resize that fits in the given dimensions
 * * Crop:          the image will be cropped to fit
 *
 * You can specify which part of the image will be preserved:
 *
 * * left_top:      the left side of a landscape image and the top of a portrait image
 * * center_top:    the center of a landscape image and the top of a portrait image
 * * right_top:     the right side of a landscape image and the top of a portrait image
 * * left_center:   the left side of a landscape image and the center of a portrait image
 * * center_center: the center of a landscape image and the center of a portrait image
 * * right_center:  the right side of a landscape image and the center of a portrait image
 * * left_bottom:   the left side of a landscape image and the bottom of a portrait image
 * * center_bottom: the center of a landscape image and the bottom of a portrait image
 * * right_bottm:   the right side of a landscape image and the bottom of a portrait image
 *
 * Usage:
 *
 *     // Stores the image in the assets/images folder
 *     $src = Image::get('example.jpg', 640, 480, 'center_center');
 *
 *     // Resizes the original image
 *     Image::resize('example.jpg', 640, 480);
 *
 * @package   Library
 * @author    Leo Feyer <https://github.com/leofeyer>
 * @copyright Leo Feyer 2005-2014
 */
class Image
{

	/**
	 * Resize or crop an image and replace the original with the resized version
	 *
	 * @param string  $image  The image path
	 * @param integer $width  The target width
	 * @param integer $height The target height
	 * @param string  $mode   The resize mode
	 *
	 * @return boolean True if the image could be resized successfully
	 */
	public static function resize($image, $width, $height, $mode='')
	{
		return static::get($image, $width, $height, $mode, $image, true) ? true : false;
	}


	/**
	 * Resize an image and store the resized version in the assets/images folder
	 *
	 * @param string  $image  The image path
	 * @param integer $width  The target width
	 * @param integer $height The target height
	 * @param string  $mode   The resize mode
	 * @param string  $target An optional target path
	 * @param boolean $force  Override existing target images
	 *
	 * @return string|null The path of the resized image or null
	 */
	public static function get($image, $width, $height, $mode='', $target=null, $force=false)
	{
		if ($image == '')
		{
			return null;
		}

		$image = rawurldecode($image);

		// Check whether the file exists
		if (!is_file(TL_ROOT . '/' . $image))
		{
			\System::log('Image "' . $image . '" could not be found', __METHOD__, TL_ERROR);
			return null;
		}

		$objFile = new \File($image, true);
		$arrAllowedTypes = trimsplit(',', strtolower(\Config::get('validImageTypes')));

		// Check the file type
		if (!in_array($objFile->extension, $arrAllowedTypes))
		{
			\System::log('Image type "' . $objFile->extension . '" was not allowed to be processed', __METHOD__, TL_ERROR);
			return null;
		}

		// No resizing required
		if (($objFile->width == $width || !$width) && ($objFile->height == $height || !$height))
		{
			// Return the target image (thanks to Tristan Lins) (see #4166)
			if ($target)
			{
				// Copy the source image if the target image does not exist or is older than the source image
				if (!file_exists(TL_ROOT . '/' . $target) || $objFile->mtime > filemtime(TL_ROOT . '/' . $target))
				{
					\Files::getInstance()->copy($image, $target);
				}

				return \System::urlEncode($target);
			}

			return \System::urlEncode($image);
		}

		// No mode given
		if ($mode == '')
		{
			// Backwards compatibility
			if ($width && $height)
			{
				$mode = 'center_top';
			}
			else
			{
				$mode = 'proportional';
			}
		}

		// Backwards compatibility
		if ($mode == 'crop')
		{
			$mode = 'center_center';
		}

		$strCacheKey = substr(md5('-w' . $width . '-h' . $height . '-' . $image . '-' . $mode . '-' . $objFile->mtime), 0, 8);
		$strCacheName = 'assets/images/' . substr($strCacheKey, -1) . '/' . $objFile->filename . '-' . $strCacheKey . '.' . $objFile->extension;

		// Check whether the image exists already
		if (!\Config::get('debugMode'))
		{
			// Custom target (thanks to Tristan Lins) (see #4166)
			if ($target && !$force)
			{
				if (file_exists(TL_ROOT . '/' . $target) && $objFile->mtime <= filemtime(TL_ROOT . '/' . $target))
				{
					return \System::urlEncode($target);
				}
			}

			// Regular cache file
			if (file_exists(TL_ROOT . '/' . $strCacheName))
			{
				// Copy the cached file if it exists
				if ($target)
				{
					\Files::getInstance()->copy($strCacheName, $target);
					return \System::urlEncode($target);
				}

				return \System::urlEncode($strCacheName);
			}
		}

		// HOOK: add custom logic
		if (isset($GLOBALS['TL_HOOKS']['getImage']) && is_array($GLOBALS['TL_HOOKS']['getImage']))
		{
			foreach ($GLOBALS['TL_HOOKS']['getImage'] as $callback)
			{
				$return = \System::importStatic($callback[0])->$callback[1]($image, $width, $height, $mode, $strCacheName, $objFile, $target);

				if (is_string($return))
				{
					return \System::urlEncode($return);
				}
			}
		}

		// Return the path to the original image if it cannot be handled
		if ($objFile->isSvgImage && !extension_loaded('dom') || $objFile->isGdImage && (!extension_loaded('gd') || $objFile->width > \Config::get('gdMaxImgWidth') || $objFile->height > \Config::get('gdMaxImgHeight') || (!$width && !$height) || $width > \Config::get('gdMaxImgWidth') || $height > \Config::get('gdMaxImgHeight')))
		{
			return \System::urlEncode($image);
		}

		// Mode-specific changes
		if ($width && $height)
		{
			switch ($mode)
			{
				case 'proportional':
					if ($objFile->width >= $objFile->height)
					{
						unset($height);
					}
					else
					{
						unset($width);
					}
					break;

				case 'box':
					if (round($objFile->height * $width / $objFile->width) <= $height)
					{
						unset($height);
					}
					else
					{
						unset($width);
					}
					break;
			}
		}

		// Create the resized image
		if ($objFile->isSvgImage)
		{
			static::resizeSvgImage($image, $width, $height, $mode, $objFile, $strCacheName);
		}
		elseif (static::resizeGdImage($image, $width, $height, $mode, $objFile, $strCacheName) === false)
		{
			return null;
		}

		// Resize the original image
		if ($target)
		{
			\Files::getInstance()->copy($strCacheName, $target);

			return \System::urlEncode($target);
		}

		// Set the file permissions when the Safe Mode Hack is used
		if (\Config::get('useFTP'))
		{
			\Files::getInstance()->chmod($strCacheName, \Config::get('defaultFileChmod'));
		}

		// Return the path to new image
		return \System::urlEncode($strCacheName);
	}


	/**
	 * Generate an image tag and return it as string
	 *
	 * @param string $src        The image path
	 * @param string $alt        An optional alt attribute
	 * @param string $attributes A string of other attributes
	 *
	 * @return string The image HTML tag
	 */
	public static function getHtml($src, $alt='', $attributes='')
	{
		$static = TL_FILES_URL;
		$src = rawurldecode($src);

		if (strpos($src, '/') === false)
		{
			if (strncmp($src, 'icon', 4) === 0)
			{
				$static = TL_ASSETS_URL;
				$src = 'assets/contao/images/' . $src;
			}
			else
			{
				$src = 'system/themes/' . \Backend::getTheme() . '/images/' . $src;
			}
		}

		if (!file_exists(TL_ROOT .'/'. $src))
		{
			return '';
		}

		$size = getimagesize(TL_ROOT .'/'. $src);
		return '<img src="' . $static . \System::urlEncode($src) . '" ' . $size[3] . ' alt="' . specialchars($alt) . '"' . (($attributes != '') ? ' ' . $attributes : '') . '>';
	}


	/**
	 * Resize an SVG image
	 *
	 * @param string  $image        The image path
	 * @param integer $width        The target width
	 * @param integer $height       The target height
	 * @param string  $mode         The resize mode
	 * @param \File   $objFile      The file object
	 * @param string  $strCacheName The name of the cached file
	 *
	 * @return boolean False if the target image cannot be created
	 */
	protected static function resizeSvgImage($image, $width, $height, $mode, $objFile, $strCacheName)
	{
		$doc = new \DOMDocument();
		$doc->loadXML($objFile->getContent());

		$svgElement = $doc->documentElement;

		// Advanced crop modes
		switch ($mode)
		{
			case 'left_top':
				$svgElement->setAttribute('preserveAspectRatio', 'xMinYMin slice');
				break;

			case 'center_top':
				$svgElement->setAttribute('preserveAspectRatio', 'xMidYMin slice');
				break;

			case 'right_top':
				$svgElement->setAttribute('preserveAspectRatio', 'xMaxYMin slice');
				break;

			case 'left_center':
				$svgElement->setAttribute('preserveAspectRatio', 'xMinYMid slice');
				break;

			case 'center_center':
				$svgElement->setAttribute('preserveAspectRatio', 'xMidYMid slice');
				break;

			case 'right_center':
				$svgElement->setAttribute('preserveAspectRatio', 'xMaxYMid slice');
				break;

			case 'left_bottom':
				$svgElement->setAttribute('preserveAspectRatio', 'xMinYMax slice');
				break;

			case 'center_bottom':
				$svgElement->setAttribute('preserveAspectRatio', 'xMidYMax slice');
				break;

			case 'right_bottom':
				$svgElement->setAttribute('preserveAspectRatio', 'xMaxYMax slice');
				break;
		}

		// This is a workaround at most, because the viewBox dimensions have
		// nothing to do with the target width and height of the image (FIXME)
		if (!$svgElement->hasAttribute('viewBox'))
		{
			$svgElement->setAttribute('viewBox', '0 0 ' . $width . ' ' . $height);
		}

		$svgElement->setAttribute('width', $width . 'px');
		$svgElement->setAttribute('height', $height . 'px');

		$xml = $doc->saveXML();
#dump(specialchars($xml));
		$objCacheFile = new \File($strCacheName, true);
		$objCacheFile->write($xml);
		$objCacheFile->close();
	}


	/**
	 * Resize a GDlib image
	 *
	 * @param string  $image        The image path
	 * @param integer $width        The target width
	 * @param integer $height       The target height
	 * @param string  $mode         The resize mode
	 * @param \File   $objFile      The file object
	 * @param string  $strCacheName The name of the cached file
	 *
	 * @return boolean False if the target image cannot be created
	 */
	protected static function resizeGdImage($image, $width, $height, $mode, $objFile, $strCacheName)
	{
		$intPositionX   = 0;
		$intPositionY   = 0;
		$intWidth       = $width;
		$intHeight      = $height;
		$strNewImage    = null;
		$strSourceImage = null;

		// Resize width and height and crop the image if necessary
		if ($intWidth && $intHeight)
		{
			if (($intWidth * $objFile->height) != ($intHeight * $objFile->width))
			{
				$intWidth = max(round($objFile->width * $height / $objFile->height), 1);
				$intPositionX = -intval(($intWidth - $width) / 2);

				if ($intWidth < $width)
				{
					$intWidth = $width;
					$intHeight = max(round($objFile->height * $width / $objFile->width), 1);
					$intPositionX = 0;
					$intPositionY = -intval(($intHeight - $height) / 2);
				}
			}

			// Advanced crop modes
			switch ($mode)
			{
				case 'left_top':
					$intPositionX = 0;
					$intPositionY = 0;
					break;

				case 'center_top':
					$intPositionX = -intval(($intWidth - $width) / 2);
					$intPositionY = 0;
					break;

				case 'right_top':
					$intPositionX = -intval($intWidth - $width);
					$intPositionY = 0;
					break;

				case 'left_center':
					$intPositionX = 0;
					$intPositionY = -intval(($intHeight - $height) / 2);
					break;

				case 'center_center':
					$intPositionX = -intval(($intWidth - $width) / 2);
					$intPositionY = -intval(($intHeight - $height) / 2);
					break;

				case 'right_center':
					$intPositionX = -intval($intWidth - $width);
					$intPositionY = -intval(($intHeight - $height) / 2);
					break;

				case 'left_bottom':
					$intPositionX = 0;
					$intPositionY = -intval($intHeight - $height);
					break;

				case 'center_bottom':
					$intPositionX = -intval(($intWidth - $width) / 2);
					$intPositionY = -intval($intHeight - $height);
					break;

				case 'right_bottom':
					$intPositionX = -intval($intWidth - $width);
					$intPositionY = -intval($intHeight - $height);
					break;
			}

			$strNewImage = imagecreatetruecolor($width, $height);
		}

		// Calculate the height if only the width is given
		elseif ($intWidth)
		{
			$intHeight = max(round($objFile->height * $width / $objFile->width), 1);
			$strNewImage = imagecreatetruecolor($intWidth, $intHeight);
		}

		// Calculate the width if only the height is given
		elseif ($intHeight)
		{
			$intWidth = max(round($objFile->width * $height / $objFile->height), 1);
			$strNewImage = imagecreatetruecolor($intWidth, $intHeight);
		}

		$arrGdinfo = gd_info();
		$strGdVersion = preg_replace('/[^0-9\.]+/', '', $arrGdinfo['GD Version']);

		switch ($objFile->extension)
		{
			case 'gif':
				if ($arrGdinfo['GIF Read Support'])
				{
					$strSourceImage = imagecreatefromgif(TL_ROOT . '/' . $image);
					$intTranspIndex = imagecolortransparent($strSourceImage);

					// Handle transparency
					if ($intTranspIndex >= 0 && $intTranspIndex < imagecolorstotal($strSourceImage))
					{
						$arrColor = imagecolorsforindex($strSourceImage, $intTranspIndex);
						$intTranspIndex = imagecolorallocate($strNewImage, $arrColor['red'], $arrColor['green'], $arrColor['blue']);
						imagefill($strNewImage, 0, 0, $intTranspIndex);
						imagecolortransparent($strNewImage, $intTranspIndex);
					}
				}
				break;

			case 'jpg':
			case 'jpeg':
				if ($arrGdinfo['JPG Support'] || $arrGdinfo['JPEG Support'])
				{
					$strSourceImage = imagecreatefromjpeg(TL_ROOT . '/' . $image);
				}
				break;

			case 'png':
				if ($arrGdinfo['PNG Support'])
				{
					$strSourceImage = imagecreatefrompng(TL_ROOT . '/' . $image);

					// Handle transparency (GDlib >= 2.0 required)
					if (version_compare($strGdVersion, '2.0', '>='))
					{
						imagealphablending($strNewImage, false);
						$intTranspIndex = imagecolorallocatealpha($strNewImage, 0, 0, 0, 127);
						imagefill($strNewImage, 0, 0, $intTranspIndex);
						imagesavealpha($strNewImage, true);
					}
				}
				break;
		}

		// The new image could not be created
		if (!$strSourceImage)
		{
			imagedestroy($strNewImage);
			\System::log('Image "' . $image . '" could not be processed', __METHOD__, TL_ERROR);

			return false;
		}

		imageinterlace($strNewImage, 1); // see #6529
		imagecopyresampled($strNewImage, $strSourceImage, $intPositionX, $intPositionY, 0, 0, $intWidth, $intHeight, $objFile->width, $objFile->height);

		// Fallback to PNG if GIF ist not supported
		if ($objFile->extension == 'gif' && !$arrGdinfo['GIF Create Support'])
		{
			$objFile->extension = 'png';
		}

		// Create the new image
		switch ($objFile->extension)
		{
			case 'gif':
				imagegif($strNewImage, TL_ROOT . '/' . $strCacheName);
				break;

			case 'jpg':
			case 'jpeg':
				imagejpeg($strNewImage, TL_ROOT . '/' . $strCacheName, (\Config::get('jpgQuality') ?: 80));
				break;

			case 'png':
				// Optimize non-truecolor images (see #2426)
				if (version_compare($strGdVersion, '2.0', '>=') && function_exists('imagecolormatch') && !imageistruecolor($strSourceImage))
				{
					// TODO: make it work with transparent images, too
					if (imagecolortransparent($strSourceImage) == -1)
					{
						$intColors = imagecolorstotal($strSourceImage);

						// Convert to a palette image
						// @see http://www.php.net/manual/de/function.imagetruecolortopalette.php#44803
						if ($intColors > 0 && $intColors < 256)
						{
							$wi = imagesx($strNewImage);
							$he = imagesy($strNewImage);
							$ch = imagecreatetruecolor($wi, $he);
							imagecopymerge($ch, $strNewImage, 0, 0, 0, 0, $wi, $he, 100);
							imagetruecolortopalette($strNewImage, false, $intColors);
							imagecolormatch($ch, $strNewImage);
							imagedestroy($ch);
						}
					}
				}

				imagepng($strNewImage, TL_ROOT . '/' . $strCacheName);
				break;
		}

		// Destroy the temporary images
		imagedestroy($strSourceImage);
		imagedestroy($strNewImage);

		return true;
	}
}
