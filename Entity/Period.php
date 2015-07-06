<?php

namespace FormaLibre\PresenceBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 * @ORM\Table(name="formalibre_presencebundle_period")
 */
 class Period
{
    
     /**
     * @ORM\Column(type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;
    /**
     * @ORM\Column(name="school_day")
     */
    protected $day;
    /**
     * @ORM\Column(name="begin_hour",type="time")
     */
    protected $beginHour;
    
    /**
     * @ORM\Column(name="end_hour",type="time")
     */
    protected $endHour;
    
    public function getId()
    {
        return $this->id;
    }
    
    public function setId($id)
    {
        $this->id = $id;
    }
    
     public function getday()
    {
        return $this->day;
    }
    
    public function setDay($day)
    {
        $this->day = $day;
    }
     public function getBenginHour()
    {
        return $this->benginHour;
    }
    
    public function setBeginHour($beginHour)
    {
        $this->beginHour = $beginHour;
    }
     public function getEndHour()
    {
        return $this->EndHour;
    }
    
    public function setEndHour($endHour)
    {
        $this->endHour = $endHour;
    }
    
    
}
