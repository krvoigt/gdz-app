<?php

namespace AppBundle\Service;

use Imagine\Image\Box;
use Imagine\Image\Point;
use Imagine\Imagick\Image;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Image manipulation service.
 */
class ImageService
{
    /*
     * Apply the requested image region as per IIIF-Image API.
     * Region parameters may be:
     *      - full
     *      - x,y,w,h
     *      - pct:x,y,w,h
     *
     * @see http://iiif.io/api/image/2.0/#region
     *
     * @param string $region  The requested image region
     * @param Image $image The image object
     *
     * @throws BadRequestHttpException if a region parameter missing or parameter out of image bound
     */
    public function getRegion($region, Image $image)
    {
        $region = trim($region);

        if ($region === 'full') {
            return $image;
        }

        $sourceImageWidth = $image->getSize()->getWidth();
        $sourceImageHeight = $image->getSize()->getHeight();

        if ($region === 'square') {
            $regionSort = 'squareBased';
        } elseif (strstr($region, 'pct')) {
            $regionSort = 'percentageBased';
        } else {
            $regionSort = 'pixelBased';
        }

        switch ($regionSort) {
            case 'squareBased':
                $calculateShorterDimension = $sourceImageWidth < $sourceImageHeight ? $sourceImageWidth : $sourceImageHeight;
                $calculateLongerDimension = $sourceImageWidth < $sourceImageHeight ? $sourceImageHeight : $sourceImageWidth;
                $imageLeftRightMargin = (($calculateLongerDimension - $calculateShorterDimension) / 2);
                $x = 0;
                $y = $imageLeftRightMargin;
                $w = $calculateShorterDimension;
                $h = $calculateShorterDimension;
                break;
            case 'pixelBased':
                $imageCoordinates = explode(',', $region);
                if (count($imageCoordinates) < 4) {
                    throw new BadRequestHttpException('Bad Request: Exactly (4) coordinates must be supplied.');
                }
                $x = $imageCoordinates[0];
                $y = $imageCoordinates[1];
                $w = $imageCoordinates[2];
                $h = $imageCoordinates[3];
                break;
            case 'percentageBased':
                $imageCoordinates = explode(',', explode(':', $region)[1]);
                if (count($imageCoordinates) < 4) {
                    throw new BadRequestHttpException('Bad Request: Exactly (4) coordinates must be supplied.');
                }
                if ((isset($imageCoordinates[0]) && $imageCoordinates[0] >= 100) ||
                        (isset($imageCoordinates[1]) && $imageCoordinates[1] >= 100)) {
                    throw new BadRequestHttpException('Bad Request: Crop coordinates are out of bound.');
                }
                $x = ceil(($imageCoordinates[0] / 100) * $sourceImageWidth);
                $y = ceil(($imageCoordinates[1] / 100) * $sourceImageHeight);
                $w = ceil(($imageCoordinates[2] / 100) * $sourceImageWidth);
                $h = ceil(($imageCoordinates[3] / 100) * $sourceImageHeight);
                break;
            default:
                $x = 0;
                $y = 0;
                $w = $sourceImageWidth;
                $h = $sourceImageHeight;
        }

        $image->crop(new Point($x, $y), new Box($w, $h));

        return $image;
    }

    /*
     * Apply the requested image size as per IIIF-Image API
     * Size parameters may be:
     *      - full
     *      - w,
     *      - ,h
     *      - pct:n
     *      - w,h
     *      - !w,h
     *
     * @see http://iiif.io/api/image/2.0/#size
     *
     * @param string $size The requested image size
     * @param Image $image The image object
     *
     * @throws BadRequestHttpException if wrong size syntax given
     */
    public function getSize($size, Image $image)
    {
        if ($size === 'full' || $size === 'max') {
            return $image;
        }

        $rawSize = $size;
        if (strstr($size, '!')) {
            $size = str_replace('!', '', $size);
        }
        $regionWidth = $image->getSize()->getWidth();
        $regionHeight = $image->getSize()->getHeight();
        if (!strstr($size, 'pct')) {
            $requestedSize = explode(',', $size);
            if (count($requestedSize) != 2) {
                throw new BadRequestHttpException(sprintf('Bad Request: Size syntax %s is not valid.', $size));
            }
            $width = $requestedSize[0];
            $height = $requestedSize[1];
            if (strstr($rawSize, '!')) {
                $w = (($regionWidth / $regionHeight) * $height);
                $h = (($regionHeight / $regionWidth) * $width);
            } else {
                if (!empty($width)) {
                    $w = $width;
                } else {
                    $w = (($regionWidth / $regionHeight) * $height);
                }
                if (!empty($height)) {
                    $h = $height;
                } else {
                    $h = (($regionHeight / $regionWidth) * $width);
                }
            }
            $image->resize(new Box($w, $h));
        } elseif (strstr($size, 'pct')) {
            $requestedPercentage = explode(':', $size)[1];
            if (is_numeric($requestedPercentage)) {
                $w = (($regionWidth * $requestedPercentage) / 100);
                $h = (($regionHeight * $requestedPercentage) / 100);
                $image->resize(new Box($w, $h));
            } else {
                throw new BadRequestHttpException(sprintf('Bad Request: Size syntax %s is not valid.', $size));
            }
        }

        return $image;
    }

    /*
     * Apply the requested image rotation as per IIIF-Image API
     * Rotation parameters may be:
     *      - n
     *      - !n
     *
     * @see http://iiif.io/api/image/2.0/#rotation
     *
     * @param string $rotation The requested image rotation
     * @param Image $image The image object
     *
     * @throws BadRequestHttpException if wrong rotation parameters provided
     */
    public function getRotation($rotation, Image $image)
    {
        if (isset($rotation) && !empty($rotation)) {
            $rotationDegree = str_replace('!', '', $rotation);
            if (intval($rotationDegree) <= 360) {
                if (strstr($rotation, '!')) {
                    $image->flipVertically();
                }
                $image->rotate(str_replace('!', '', $rotation));
            } else {
                throw new BadRequestHttpException(sprintf('Bad Request: Rotation argument %s is not between 0 and 360.', $rotationDegree));
            }
        }

        return $image;
    }

    /*
     * Apply the requested image quality as per IIIF-Image API
     *
     * Quality parameters may be:
     *      - color
     *      - gray
     *      - bitonal
     *      - default
     *
     * @see http://iiif.io/api/image/2.0/#quality
     *
     * @param string $quality The requested image quality
     * @param Image $image The image object
     *
     * @throws BadRequestHttpException if wrong quality parameters provided
     */
    public function getQuality($quality, Image $image)
    {
        if ($quality === 'default' || $quality === 'color') {
            return $image;
        }

        switch ($quality) {
            case 'gray':
                $image->effects()->grayscale();
                break;
            case 'bitonal':
                $max = $image->getImagick()->getQuantumRange();
                $max = $max['quantumRangeLong'];
                $imageClearnessFactor = 0.20;
                $image->getImagick()->thresholdImage($max * $imageClearnessFactor);
                break;
            default:
                throw new BadRequestHttpException(sprintf('Bad Request: %s is not a supported quality.', $quality));
        }

        return $image;
    }
}
