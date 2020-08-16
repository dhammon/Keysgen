<?php
require_once __DIR__ . "/../config/Config.php";


class PhotoOptimizer
{
    private $image;
    private $contrastValue;
    private $contrastCountMax;
    private $imageStats;

    public function __construct()
    {
        $this->contrastValue = Config::$photoOptimizerSettings['contrastValue'];
        $this->contrastCountMax = Config::$photoOptimizerSettings['contrastCountMax'];
    }

    public function setImage($image)
    {
        $this->image = $image;
    }

    public function getImage()
    {
        return $this->image;
    }

    public function getImageStats()
    {
        return $this->imageStats;
    }

    public function optimizeImage()
    {
        $optimized = "no";
        $contrastValue = $this->contrastValue;
        $contrastCountMax = $this->contrastCountMax;
        $contrastCount = 0;
        $image = $this->image;

        while ($optimized === "no")
        {
            $survey = $this->surveyImageBackground();
            $avgStdDevs = $survey['meta']['avgStdDevs'];
            $contrastType = $survey['meta']['contrastType'];
            $middleStdDev = $survey['stdDevs']['middle'];

            if($avgStdDevs == 0 && $middleStdDev != 0)
            {
                $this->imageStats = $survey;
                $optimized = "yes";
            }
            else
            {
                imagefilter($image, IMG_FILTER_BRIGHTNESS, $contrastType.$contrastValue);
                $contrastCount++;
            }

            if($contrastCount >= $contrastCountMax)
            {
                throw new ExceptionPhotoOptimizer("Image background is not solid enough or the entire key is not present");
            }
        }
    }

    //todo validate image size at least 50x50 pixels

//todo smartCropImage (finds most solid area of pic to crop to?)
    private function cropImage($ratio)
    {
        $imageWidth = imagesx($this->image);
        $imageHeight = imagesy($this->image);
        $x = $imageWidth*($ratio/2);
        $y = $imageHeight*($ratio/2);
        $width = $imageWidth-($imageWidth*($ratio));
        $height = $imageHeight-($imageHeight*($ratio));
        $crop = ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height];
        $croppedImage = imagecrop($this->image, $crop);

        return $croppedImage;
    }

    private function surveyImageBackground()
    {
        $image = $this->image;
        $columnCount = imagesx($image);
        $rowCount = imagesy($image);

        $topSample = $this->sampleColor($image, 2, "row");
        $bottomSample = $this->sampleColor($image, $rowCount - 2, "row");
        $leftSample = $this->sampleColor($image, 2, "col");
        $rightSample = $this->sampleColor($image, $columnCount - 2, "col");
        $middleSample = $this->sampleColor($image, round($rowCount/2), "row");

        $topAverage = $this->calculateAverage($topSample);
        $bottomAverage = $this->calculateAverage($bottomSample);
        $leftAverage = $this->calculateAverage($leftSample);
        $rightAverage = $this->calculateAverage($rightSample);
        $middleAverage = $this->calculateAverage($middleSample);
        $averages = array($topAverage, $bottomAverage, $leftAverage, $rightAverage);
        $avgAverages = $this->calculateAverage($averages);
        $contrastType = $this->findBackgroundContrastDirection($avgAverages);
        $stdAverages = $this->calculateStdDev($averages);

        $topStd = $this->calculateStdDev($topSample);
        $bottomStd = $this->calculateStdDev($bottomSample);
        $leftStd = $this->calculateStdDev($leftSample);
        $rightStd = $this->calculateStdDev($rightSample);
        $middleStd = $this->calculateStdDev($middleSample);
        $stdDevs = array($topStd, $bottomStd, $leftStd, $rightStd);
        $avgStdDevs = $this->calculateAverage($stdDevs);
        $stdStdDevs = $this->calculateStdDev($stdDevs);

        $survey = array(
            "averages" => array(
                "top" => $topAverage,
                "bottom" => $bottomAverage,
                "left" => $leftAverage,
                "right" => $rightAverage,
                "middle" => $middleAverage,
            ),
            "stdDevs" => array(
                "top" => $topStd,
                "bottom" => $bottomStd,
                "left" => $leftStd,
                "right" => $rightStd,
                "middle" => $middleStd
            ),
            "meta" => array(
                "contrastType" => $contrastType,
                "avgAverages" => $avgAverages,
                "stdAverages" => $stdAverages,
                "avgStdDevs" => $avgStdDevs,
                "stdStdDevs" => $stdStdDevs,
            ),
        );

        return $survey;
    }

    private function findBackgroundContrastDirection($avgAverages)
    {
        $midColorValue = 8388608;
        $contrastType = "";

        switch ($avgAverages)
        {
            case $avgAverages < $midColorValue:
                $contrastType = "-";
                break;
            case $avgAverages >= $midColorValue:
                $contrastType = "+";
                break;
        }

        return $contrastType;
    }

    private function sampleColor($image, $sample, $type)
    {
        $count = 0;
        ($type === "row" || $type === 0) ? $count = imagesx($image) : false;
        ($type === "col" || $type === 1) ? $count = imagesy($image) : false;
        $colors = array();

        for($i = 0; $i < $count; $i++)
        {
            $row = $sample;
            $column = $sample;
            ($type === "row" || $type === 0) ? $column = $i : false;
            ($type === "col" || $type === 1) ? $row = $i : false;
            $color = imagecolorat($image, $column, $row);

            $colors[] = $color;
        }

        return $colors;
    }

    private function calculateStdDev(array $array)
    {
        $avg = $this->calculateAverage($array);
        $count = count($array);
        $sumOfSquares = 0;

        for($i=0; $i<$count; $i++)
        {
            $sumOfSquares += ($array[$i] - $avg) * ($array[$i] - $avg);
        }

        $variance = $sumOfSquares / ($count - 1);
        $stdDev = sqrt($variance);

        return $stdDev;
    }

    private function calculateAverage(array $array)
    {
        $count = count($array);
        $sum = array_sum($array);
        $avg = $sum / $count;

        return $avg;
    }
}
