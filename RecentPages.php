<?php
/**
 * RecentPages
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * http://www.gnu.org/copyleft/gpl.html
 */

define( 'RP_VERSION', '0.1.8, 2014-01-18' );

// Extension credits that show up on Special:Version
$wgExtensionCredits['parserhook'][] = array(
    'name' => 'Recently Created Page List',
    'url' => 'http://www.mediawiki.org/wiki/Extension:Recent_Pages',
    'version' => RP_VERSION,
    'author' => 'Nathan Larson',
    'description' => 'Parser hook to list recently created or random pages'
);

// Minimum page length of a randomly-selected article
$wgRecentPagesDefaultMinimumLength = 600;
// Default number of articles to pull back
$wgRecentPagesDefaultLimit = 6;
// Maximum number of attempts to get a unique random article
$wgRecentPagesMaxAttempts = 1000;
// Due to a glitch, leave this set to true
$wgRecentPagesDisableOtherNamespaces = true;
// Shall we sort by default?
$wgRecentPagesDefaultSort = false;

// Avoid unstubbing $wgParser on setHook() too early on modern (1.12+) MW versions, as
// per r35980
if ( defined( 'MW_SUPPORTS_PARSERFIRSTCALLINIT' ) ) {
    $wgHooks['ParserFirstCallInit'][] = 'rpInit';
} else {
    $wgExtensionFunctions[] = 'rpInit';
}

function rpInit() {
    // TODO: Remove global
    global $wgParser;
    $wgParser->setHook ( 'recent', 'RecentPages::showRecentPages' );
    $wgParser->setHook ( 'random', 'RecentPages::showRandomPages' );
    return true;
}

class RecentPages {
    public static function showRecentPages( $text, $args, $parser ) {
        global $wgBedellPenDragonResident;
        global $wgUser;
        global $wgContentNamespaces;
        global $wgRecentPagesDefaultMinimumLength;
        global $wgRecentPagesDefaultLimit;
        global $wgRecentPagesMaxAttempts;
        global $wgRecentPagesDisableOtherNamespaces;
        global $wgRecentPagesDefaultSort;

        # Prevent caching
        $parser->disableCache();

        $skin = $wgUser->getSkin();

        $ret = "";

        $sort = $wgRecentPagesDefaultSort;
        if ( isset ( $args['sort'] ) ) {
            $sort = true;
        }
        // "limit" is what to limit the database query to
        $limit = $wgRecentPagesDefaultLimit;
        if ( isset( $args['limit'] ) ) {
            if ( is_numeric( $args['limit'] ) ) {
                $limit = $args['limit'];
            }
        }
        $bulletChar = "*";
        if ( isset ( $args['bulletchar'] ) ) {
            $bulletChar = $args['bulletchar'];
        }
        $liChar = "<li>";
        if ( isset ( $args['lichar'] ) ) {
            $liChar = $args['lichar'];
        }
        $endliChar = "</li>";
        if ( isset ( $args['endlichar'] ) ) {
            $endliChar = $args['endlichar'];
        }
        $ulChar = "<ul>";
        if ( isset ( $args['ulchar'] ) ) {
            $ulChar = $args['ulchar'];
        }
        $endulChar = "</ul>";
        if ( isset ( $args['endulchar'] ) ) {
            $endulChar = $args['endulchar'];
        }
        $endChar = "\n";
        if ( isset ( $args['endchar'] ) ) {
            $endChar = $args['endchar'];
        }
        $parsedEndChar = "";
        if ( isset ( $args['parsedendchar'] ) ) {
            $parsedEndChar = $args['parsedendchar'];
        }
        // "minimum" is the minimum page length
        $minimum = $wgRecentPagesDefaultMinimumLength;
        if ( isset( $args['minimum'] ) ) {
            if ( is_numeric( $args['minimum'] ) ) {
                $minimum = $args['minimum'];
            }
        }
        $prop = array();
        $displayTitles = array();
        // "maxresults" is what to limit the results to
        if ( isset ( $wgBedellPenDragonResident ) ) {
            if ( !isset ( $args['maxresults'] ) ) {
                $maxResults = $limit;
            } else {
                $maxResults = $args['maxresults'];
            }
        }
        if ( isset( $args['random'] ) ) {
            if ( ! isset ( $args[ 'limit' ] ) ) {
                $args['limit'] = $wgRecentPagesDefaultLimit;
            }
            $namespace = MWNamespace::getValidNamespaces();
            if ( isset( $args['namespace'] ) ) {
                switch ( $args[ 'namespace' ] ) {
                    case 'all':
                        $namespace = MWNamespace::getValidNamespaces();
                        break;
                    case 'content':
                        $namespace = MWNamespace::getContentNamespaces();
                        break;
                    default:
                        $namespace = array ( RecentPages::rpGetNSID ( $args['namespace'] ) );
                        if ( trim( strtolower( $args['namespace'] ) ) === 'main' ) {
                            $namespace = array( 0 );
                        }
                        if ( !$namespace && $namespace !== array ( 0 ) ) {
                            // If an invalid namespace name was given, use all possible
                            // namespaces
                            $namespace = MWNamespace::getValidNamespaces();
                        } else {
                            $setTheNamespace = true;
                        }
                }
            }
            $attempts = 0;
            $retArrayPageId = array();
            for ( $count = 0; $count < $args['limit']; $count++ ) {
                // Avoid infinite loops
                $titleCandidate = false;
                while ( $attempts < $wgRecentPagesMaxAttempts && !$titleCandidate ) {
                    $titleCandidate = false;
                    while ( !$titleCandidate && $attempts < $wgRecentPagesMaxAttempts ) {
                        $attempts++;
                        $randomPage = new RecentPagesRandomPageWithMinimumLength (
                            $minimum, $namespace );
                        $titleCandidate = $randomPage->getRandomTitle();
                        if ( in_array ( $titleCandidate->getArticleID(), $retArrayPageId ) ) {
                            $titleCandidate = false;
                        }
                        if ( $titleCandidate ) {
                            if ( ( !$wgRecentPagesDisableOtherNamespaces
                            || $namespace === array ( 0 ) ) &&
                            !in_array ( $titleCandidate->getNamespace(), $namespace ) ) {
                                $titleCandidate = false;
                            } elseif ( isset ( $args['prop'] ) && isset (
                                $wgBedellPenDragonResident ) ) {
                                $titleFullText = $titleCandidate->getFullText();
                                $propValue = BedellPenDragon::renderGetBpdProp( $parser,
                                    $titleFullText, $args['prop'], true );
                                if ( $propValue == BPD_NOPROPSET ) {
                                    $titleCandidate = false;
                                }
                            }
                        }
                    }
                    if ( $titleCandidate && $attempts < $wgRecentPagesMaxAttempts ) {
                        $retArrayPageId [ $count ] = $titleCandidate->getArticleID();
                        $retArray[ $count ] = $titleCandidate;
                    }
                }
            }
            if ( isset ( $retArray ) ) {
                $numRows = count ( $retArray );
            }
        } else {
            $limitArr = array( "ORDER BY" => "page_id desc limit $wgRecentPagesDefaultLimit" );
            if ( isset( $args['limit'] ) ) {
                if ( is_numeric( $args['limit'] ) ) {
                        $limitArr = array( "ORDER BY" => "page_id desc limit " . $args['limit'] );
                } elseif ( $args['limit'] == "none" ) {
                    $limitArr = "";
                }
            }
            if ( isset( $args['namespace'] ) ) {
                switch ( $args['namespace'] ) {
                    case 'all':
                        $where = array ( "page_is_redirect" => 0,
                            "page_len>{$minimum}",
                        );
                        break;
                    case 'content':
                        $where = "page_is_redirect=0 AND (";
                        $isFirstOne = true;
                        foreach ( $wgContentNamespaces as $thisNameSpace ) {
                            if ( !$isFirstOne ) {
                                $where .= " OR ";
                            }
                            $isFirstOne = false;
                            $where .= "page_namespace = $thisNameSpace";
                        }
                        $where .= ")";
                        $where .= " AND page_len>{$minimum}";
                        break;
                    default:
                        $where = array (
                            "page_namespace" => RecentPages::rpGetNSID( $args['namespace'] ),
                            "page_is_redirect" => 0,
                            "page_len>{$minimum}",
                        );
                        break;
                }
            } else {
                $where = array (
                    "page_namespace" => 0,
                    "page_is_redirect" => 0,
                    "page_len>{$minimum}",
                );
            }
            $tables = array( 'page', 'page_props' );
            $fields = array( 'page_id', 'page_title', 'page_namespace', 'pp_page', 'pp_propname',
                'pp_value' );
            if ( isset( $args['prop'] ) ) {
                $typeJoin = 'INNER JOIN';
            } else {
                $typeJoin = 'LEFT JOIN';
            }
            $join = array( 'page_props' => array( $typeJoin, array(
                    'page_id=pp_page' ) ) );
            $dbr = wfGetDB( DB_SLAVE );
            $res = $dbr->select(
                $tables,
                $fields,
                $where,
                __METHOD__,
                $limitArr,
                $join
            );
            if ( $res ) {
                $numRows = $dbr->numRows( $res );
            }
        }
        if ( !isset ( $retArray ) ) {
            $retArray = array();
        }

        if ( isset ( $res ) ) {
            $numRows = 0;
            foreach ( $res as $row ) {
                // TODO: Get rid of this O(n) looped query database inefficiency!
                $title = Title::newFromText ( $row->page_title, $row->page_namespace );
                if ( isset ( $args['prop'] ) ) {
                    if ( $row->pp_propname == 'bpd_' . $args['prop'] ) {
                        $prop[$title->getFullText()] = $parser->recursiveTagParse (
                            BedellPenDragon::stripRefTags ( $row->pp_value ) );
                        $retArray[] = $title;
                        $numRows++;
                    }
                } else {
                    $retArray[] = $title;
                    $numRows++;
                }
                if ( $row->pp_propname == 'displaytitle' ) {
                    $displayTitles[$row->page_id] = $row->pp_value;
                }
                if ( $numRows == $maxResults ) {
                        break;
                }
            }
            #$args['random'] = true;

        }
        if ( $sort ) {
            usort ( $retArray, 'RecentPages::cmpTitle' );
        }
        if ( $retArray ) {
            // Handle situations where we're getting a property
            if ( isset ( $args['prop'] ) && isset ( $wgBedellPenDragonResident )
                && isset( $args['random'] ) ) {
                $numRows = 0;
                $newRetArray = array();
                foreach ( $retArray as $retArrayElement ) {
                    $retArrayElementFullText = $retArrayElement->getFullText();
                    $propValue = BedellPenDragon::renderGetBpdProp( $parser,
                        $retArrayElementFullText,
                        $args['prop'], true );
                    if ( ( $propValue != BPD_NOPROPSET && !isset ( $args['invertprop'] ) ) ||
                          ( $propValue == BPD_NOPROPSET && isset ( $args['invertprop'] ) ) ) {
                        $newRetArray[] = $retArrayElement;
                        $prop[$retArrayElementFullText] = $propValue;
                        $numRows++;
                    }
                    if ( $numRows == $maxResults ) {
                        break;
                    }
                }
                $retArray = $newRetArray;
            }
            // Display differently depending on how many columns there are
            if ( !isset ( $args['columns'] ) ) {
                $args['columns'] = 1;
            }
            if ( $args['columns'] == 3 && $numRows > 2 ) {
                $ret = "{|\n|-\n| valign=\"top\" style=\"width:33%\"|\n";
                for ( $i = 1; $i <= ceil ( $numRows / 3 ); $i++ ) {
                    $title = $retArray[ $i - 1 ];
                    if ( !is_null( $title ) ) {
                        $html = RecentPages::getDisplayTitle ( $title, $args, $displayTitles );
                    $ret .= $bulletChar . $parser->internalParse ( '[[' . $title->getFullText()
                        . '|' . $html . ']]' ) . $endChar
                        . $parser->internalParse( str_replace ( '$1', $fullText, $parsedEndChar ) );
                    }
                }
                $ret .= "| valign=\"top\"|\n";
                for ( $i = ceil ( $numRows / 3 ); $i < $numRows * ( 2 / 3); $i++ ) {
                    $title = $retArray[ $i ];
                    if ( !is_null( $title ) ) {
                        $html = RecentPages::getDisplayTitle ( $title, $args, $displayTitles );
                    $ret .= $bulletChar . $parser->internalParse ( '[[' . $title->getFullText()
                        . '|' . $html . ']]' ) . $endChar
                        . $parser->internalParse( str_replace ( '$1', $fullText, $parsedEndChar ) );
                    }
                }
                $ret .= "| valign=\"top\"|\n";
                for ( $i = ceil ( $numRows * ( 2 / 3 ) ); $i < $numRows; $i++ ) {
                    $title = $retArray[ $i ];
                    if ( !is_null( $title ) ) {
                        $html = RecentPages::getDisplayTitle ( $title, $args, $displayTitles );
                    $ret .= $bulletChar . $parser->internalParse ( '[[' . $title->getFullText()
                        . '|' . $html . ']]' ) . $endChar
                        . $parser->internalParse( str_replace ( '$1', $fullText, $parsedEndChar ) );
                    }
                }
                $ret .= "|}";
                $ret = $parser->doTableStuff ( $ret );
            } elseif ( $args['columns'] != 2 || $numRows == 1 ) {
                $ret = "<div id='recentpages'><ul>";
                for ( $i = 1; $i <= $numRows; $i++ ) {
                    $title = $retArray[ $i - 1 ];
                    if ( !is_null( $title ) ) {
                        $html = RecentPages::getDisplayTitle ( $title, $args, $displayTitles );
                        $fullText = $title->getFullText();
                        $urlEncoded = $title->getPrefixedUrl();
                        $ret .= $liChar;
                        if ( isset ( $args['stripfromfront'] ) ) {
                            if ( substr ( $title, 0, strlen ( $args['stripfromfront'] ) ) ==
                                $args['stripfromfront'] ) {
                                $html = preg_replace( '/' . $args['stripfromfront'] . '/',
                                    '', $html, 1 );
                            }
                        }
                        if ( isset ( $args['prop'] ) && isset ( $wgBedellPenDragonResident ) ) {
                            if ( isset ( $args['str_replace_title'] ) ) {
                                $str_replaced = str_replace ( '$1', $fullText,
                                    $args['str_replace_title'] );
                                $str_replaced = str_replace ( '$2', $html,
                                    $str_replaced );
                                $ret .= $parser->internalParse ( $str_replaced );
                            } else {
                                #$ret .= $parser->internalParse ( $fullText );
                            }
                            if ( isset ( $args['spaces_between'] ) ) {
                                $ret .= ' ';
                            }
                            if ( isset ( $args['str_replace_prop'] ) ) {
                                $ret .= str_replace ( '$1', $prop[$fullText],
                                    $args['str_replace_prop'] );
                            } else {
                                $ret .= $prop[$fullText];
                            }
                        } else {
                            $ret .= $parser->internalParse ( '[[' . $fullText
                            . '|' . $html . ']]' );
                        }
                        if ( isset ( $args['editlink'] ) ) {
                            $replacedItWith = $parser->internalParse( str_replace ( '$1',
                                $urlEncoded, $args['editlink'] ) );
                            $ret .= $replacedItWith;
                        }
                        $ret .= $endliChar . $endChar
                            . $parser->internalParse( str_replace ( '$1', $fullText, $parsedEndChar ) );
                    }
                }
                $ret .= "</ul></div>\n";
            } else {
                $ret = "{|\n|-\n| valign=\"top\" style=\"width:50%\"|\n";
                for ( $i = 1; $i <= ceil ( $numRows / 2 ); $i++ ) {
                    $title = $retArray[ $i - 1 ];
                    if ( !is_null( $title ) ) {
                        $html = RecentPages::getDisplayTitle ( $title, $args, $displayTitles );
                        $ret .= $bulletChar . $parser->internalParse ( '[[' . $title->getFullText()
                            . '|' . $html . ']]' ) . $endChar
                            . $parser->internalParse( str_replace ( '$1', $fullText, $parsedEndChar ) );
                    }
                }
                $ret .= "| valign=\"top\"|\n";
                for ( $i = ceil ( $numRows / 2 ); $i < $numRows; $i++ ) {
                    $title = $retArray[ $i ];
                    if ( !is_null( $title ) ) {
                        $html = RecentPages::getDisplayTitle ( $title, $args, $displayTitles );
                        $ret .= $bulletChar . $parser->internalParse ( '[[' . $title->getFullText()
                            . '|' . $html . ']]' ) . $endChar
                            . $parser->internalParse( str_replace ( '$1', $fullText, $parsedEndChar ) );
                    }
                }
                $ret .= "|}";
                $ret = $parser->doTableStuff ( $ret );
            }
        }
        return $ret;
    }

    // Function to get namespace id from name
    public static function rpGetNSID( $namespace ) {
        if ( $namespace == "" ) {
            return 0;
        } else {
            $ns = new MWNamespace();
            return $ns->getCanonicalIndex( trim( strtolower( $namespace ) ) );
        }
    }

    public static function getDisplayTitle ( $title, $args, $displayTitles ) {
        $id = $title->getArticleID();
        if ( !isset ( $args['random'] ) ) {
            if ( isset( $displayTitles[$id] ) ) {
                return $displayTitles[$id];
            }
        } else {
            $dbr = wfGetDB( DB_SLAVE );
            $row = $dbr->selectRow ( 'page_props', array ( 'pp_value' ),
                array ( 'pp_page' => $id, 'pp_propname' => 'displaytitle' ) );
            if ( $row ) {
                return $row->pp_value;
            }
        }
        return $title->getFullText();
    }

    // Get some random pages
    public static function showRandomPages ( $text, $args, $parser ) {
        $args[ 'random' ] = true;
        return self::showRecentPages ( $text, $args, $parser );
    }

    // Alphabetize arrays of titles
    public static function cmpTitle ( $a, $b ) {
        return strcmp ( $a->getPrefixedText(), $b->getPrefixedText() );
    }
}

class RecentPagesRandomPageWithMinimumLength extends RandomPage {
    public function __construct ( $minimumLength = 0, $selNamespaces = array() ) {
        $this->extra = array ( 'page_len >= ' . $minimumLength );
        parent::__construct( 'Randompage' );
    }
}
