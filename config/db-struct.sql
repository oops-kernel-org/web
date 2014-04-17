-- phpMyAdmin SQL Dump
-- version 3.5.8.2
-- http://www.phpmyadmin.net
--
-- Verze serveru: 5.5.36-MariaDB
-- Verze PHP: 5.5.11

SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `driver` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `name` (`name`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `file` (
  `id` int(6) NOT NULL AUTO_INCREMENT,
  `name` varchar(1024) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `name` (`name`(767))
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `function` (
  `id` int(8) NOT NULL AUTO_INCREMENT,
  `name` varchar(512) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `name` (`name`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `guilty_driver` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `driverID` int(11) NOT NULL,
  `kernelID` int(11) NOT NULL,
  `count` int(11) NOT NULL,
  `tcount` int(11) NOT NULL,
  `distro` varchar(12) NOT NULL,
  `stamp` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stamp` (`stamp`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `guilty_file` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `fileID` int(11) NOT NULL,
  `kernelID` int(11) NOT NULL,
  `count` int(11) NOT NULL,
  `tcount` int(11) NOT NULL,
  `distro` varchar(12) NOT NULL,
  `stamp` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stamp` (`stamp`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `guilty_function` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `functionID` int(11) NOT NULL,
  `kernelID` int(11) NOT NULL,
  `count` int(11) NOT NULL,
  `tcount` int(11) NOT NULL,
  `distro` varchar(12) NOT NULL,
  `stamp` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stamp` (`stamp`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `guilty_kernel` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kernelID` int(11) NOT NULL,
  `count` int(11) NOT NULL,
  `tcount` int(11) NOT NULL,
  `distro` varchar(12) NOT NULL,
  `stamp` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stamp` (`stamp`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `guilty_module` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `moduleID` int(11) NOT NULL,
  `kernelID` int(11) NOT NULL,
  `count` int(11) NOT NULL,
  `tcount` int(11) NOT NULL,
  `distro` varchar(12) NOT NULL,
  `stamp` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stamp` (`stamp`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `kernel` (
  `id` int(5) NOT NULL AUTO_INCREMENT,
  `version` varchar(32) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `version` (`version`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `kffindex` (
  `kernelID` int(4) NOT NULL,
  `fileID` int(6) NOT NULL,
  `functionID` int(8) NOT NULL,
  `line` int(5) NOT NULL,
  KEY `functionID` (`functionID`),
  KEY `fileID` (`fileID`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `module` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `name` (`name`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS `raw_data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sha1` varchar(40) DEFAULT NULL,
  `raw` text NOT NULL,
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` int(4) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `status` (`status`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
