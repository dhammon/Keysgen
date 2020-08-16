<?php
require_once __DIR__ . "/../config/Config.php";
require_once Config::$pathExceptionStlWriter;

class StlWriter
{
    private $keyFile;
    private $keyFilePath;
    private $keyFileName;
    private $maxCoordinateCharLengthAllowed;
    private $minNumberOfTriangles;
    private $keyCode;
    private $keyProfile;

    public function __construct()
    {
        $this->maxCoordinateCharLengthAllowed = Config::$maxCoordinateCharLengthAllowed;
        $this->minNumberOfTriangles = Config::$minNumberOfTriangles;
        $this->keyFilePath = Config::$keyFilePath;
    }

    public function getKeyFileName()
    {
        return $this->keyFileName;
    }

    public function makeStl(array $triangles, $shaftFilePath, $keyCode, $keyProfile)
    {
        $this->keyCode = $keyCode;
        $this->keyProfile = $keyProfile;

        $this->validateTriangles($triangles);
        $this->makeKeyFile();
        $this->writeHeaderToFile();
        $this->writeTrianglesToFile($triangles);
        $this->writeShaftToFile($shaftFilePath);
        $this->writeFooterToFile();

        fclose($this->keyFile);
    }

    private function writeFooterToFile()
    {
        $footerContent = "\r\nendsolid ascii";
        file_put_contents($this->keyFilePath . $this->keyFileName, $footerContent, FILE_APPEND);
    }

    private function writeShaftToFile($shaftFilePath)
    {
        $shaftFile = file($shaftFilePath);
        file_put_contents($this->keyFilePath . $this->keyFileName, $shaftFile, FILE_APPEND);
    }

    private function writeTrianglesToFile(array $triangles)
    {
        foreach($triangles as $triangle)
        {
            $this->writeToFile($triangle);
        }
    }

    private function writeToFile(array $vertices)
    {
        $triangle = "
         facet normal 0 0 0\r
          outer loop\r
           vertex   ".$vertices[0][0]." ".$vertices[0][1]." ".$vertices[0][2]."\r
           vertex   ".$vertices[1][0]." ".$vertices[1][1]." ".$vertices[1][2]."\r
           vertex   ".$vertices[2][0]." ".$vertices[2][1]." ".$vertices[2][2]."\r
          endloop\r
         endfacet\r
        ";
        fwrite($this->keyFile, $triangle);
    }

    private function writeHeaderToFile()
    {
        $this->keyFile = $this->openFile($this->keyFilePath . $this->keyFileName);
        $head = "solid ascii\r
                 facet normal 0 0 0\r
                  outer loop\r
                   vertex 0 0 0\r
                   vertex 0 0 0\r
                   vertex 0 0 0\r
                  endloop\r
                 endfacet\r
                ";
        fwrite($this->keyFile, $head);
    }

    private function openFile($fileName)
    {
        $this->validateFileExists($fileName);
        $file = fopen($fileName, "w");
        $this->validateFileOpen($file);

        return $file;
    }

    private function makeKeyFile()
    {
        $this->generateKeyFileName();
        $file = fopen($this->keyFilePath . $this->keyFileName, "w");
        fclose($file);
    }

    private function generateKeyFileName()
    {
        $keyCode = $this->keyCode;
        $keyProfile = $this->keyProfile;
        $time = time();
        $this->keyFileName = $keyProfile."-".$keyCode."-".$time.".stl";
    }

    private function validateFileExists($fileName)
    {
        if(!file_exists($fileName))
        {
            throw new ExceptionStlWriter("File does not exist");
        }
    }

    private function validateFileOpen($file)
    {
        if(!$file || get_resource_type($file) == 'Unknown')
        {
            throw new ExceptionStlWriter("Unable to open file.");
        }
    }

    private function validateTriangles($triangles)
    {
        $this->validateIsArray($triangles);
        $this->validateArrayOfArrays($triangles);
        $this->validateArrayOfArraysOfArrays($triangles);
        $this->validateCoordinates($triangles);
        $this->validateTriangleCount($triangles);
        $this->validatePointCount($triangles);
        $this->validateCoordinateCount($triangles);
    }

    private function validateCoordinateCount($triangles)
    {
        foreach($triangles as $triangle)
        {
            foreach($triangle as $coordinate)
            {
                $count = count($coordinate);

                if($count != 3)
                {
                    throw new ExceptionStlWriter("Insufficient number coordinates to make a vertex");
                }
            }
        }
    }

    private function validatePointCount($triangles)
    {
        foreach($triangles as $triangle)
        {
            $count = count($triangle);

            if($count != 3)
            {
                throw new ExceptionStlWriter("Insufficient number of vertices to make triangles");
            }
        }
    }

    private function validateTriangleCount($triangles)
    {
        $count = count($triangles);

        if($count < $this->minNumberOfTriangles)
        {
            throw new ExceptionStlWriter("Too few triangles available");
        }
    }

    private function validateCoordinates($triangles)
    {
        foreach($triangles as $triangle)
        {
            foreach($triangle as $point)
            {
                foreach($point as $coordinates)
                {
                    $this->validateIsNumeric($coordinates);
                    $this->validateCharCountLessThan($coordinates, $this->maxCoordinateCharLengthAllowed);
                }
            }
        }
    }

    private function validateCharCountLessThan($value, $count)
    {
        $valueString = (string)$value;
        $charCount = strlen($valueString);

        if($charCount >= $count)
        {
            throw new ExceptionStlWriter("Point value character length too long (>=$count)");
        }
    }

    private function validateIsNumeric($value)
    {
        if(!is_numeric($value))
        {
            throw new ExceptionStlWriter("Points are not numeric");
        }
    }

    private function validateArrayOfArraysOfArrays($triangles)
    {
        foreach($triangles as $triangle)
        {
            foreach($triangle as $point)
            {
                $this->validateIsArray($point);
            }
        }
    }

    private function validateArrayOfArrays($triangles)
    {
        foreach($triangles as $triangle)
        {
            $this->validateIsArray($triangle);
        }
    }

    private function validateIsArray($array)
    {
        if(!is_array($array))
        {
            throw new ExceptionStlWriter("Triangles is not an array");
        }
    }
}
