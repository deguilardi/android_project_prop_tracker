<?php
include "CacheableFile.php";
include "entities/CommitEntity.php";
include "entities/RepositoryEntity.php";

class GitFile extends CacheableFile{

    protected $repoEntity;
    protected $path;
    protected $commits = array();

    public function __construct( $repoEntity, $path ){
        $this->repoEntity = $repoEntity;
        $this->path = $path;
        parent::__construct( $this->repoEntity->getRawPathUrlForFile( $this->path ), $this->repoEntity->repo );
    }

    protected function loadCommits(){
        if( $this->loaded && !$this->hasError ){
            $this->loadCommitsFromPage( $this->repoEntity->getCommitsListUrlForFile( $this->path ) );
        }
    }

    private function loadCommitsFromPage( $url ){
        $commitsListFile = new CacheableFile( $url, $this->repoEntity->repo );
        $commitsListFile->load();

        if( !$commitsListFile->content ){
            return;
        }

        $htmlDoc = new DOMDocument();
        libxml_use_internal_errors( true );
        $htmlDoc->loadHTML( $commitsListFile->content );
        $htmlElem = $htmlDoc->childNodes->item( 1 );
        $bodyElem = $htmlElem->childNodes->item( 3 );

        // this element can be in many different positions
        foreach( $bodyElem->childNodes as $item ){
            if( $item->nodeType == XML_ELEMENT_NODE && strpos( $item->getAttribute( "class" ), "application-main") !== false ){
                $appElem = $item;break;
            }
        }

        $mainElem = $appElem->childNodes->item( 1 )->childNodes->item( 1 );
        $repoContentElem = $mainElem->childNodes->item( 3 )->childNodes->item( 1 );
        $commitsListElem = $repoContentElem->childNodes->item( 3 );

        foreach( $commitsListElem->childNodes as $item ){
            if( $item->nodeType != XML_ELEMENT_NODE ){ continue; }

            if( $item->getAttribute( "class" ) == "commit-group-title" ){
                $date = $this->extractCommitDateFromCommitsListTitleTag( $item );
            }
            else if( $item->tagName == "ol" ){
                foreach( $item->childNodes as $commitItem ){
                    if( $commitItem->nodeType != XML_ELEMENT_NODE ){ continue; }
                    if( $commitItem->tagName != "li" ){ continue; }
                    $hash = $this->extractCommitHashFromCommitsListItemTag( $commitItem );
                    $this->commits[] = new CommitEntity( $date, $hash );
                }
            }
        }

        // check for other pages
        $pagesContainersElem = $repoContentElem->childNodes->item( 5 );
        if( $pagesContainersElem ){
            $nextPageElem = $pagesContainersElem->childNodes->item( 1 )->childNodes->item( 1 );
            $href = $nextPageElem->getAttribute( "href" );
            if( $href ){
                $this->loadCommitsFromPage( $href );
            } 
        }
    }

    private function extractCommitDateFromCommitsListTitleTag( $item ){
        $value = trim( $item->nodeValue );
        $value = str_replace( "Commits on ", "", $value );
        return date_parse( $value );
    }

    private function extractCommitHashFromCommitsListItemTag( $item ){
        $info = $item->getAttribute( "data-channel" );
        $matches = array();
        $regexp = "/(repo:[0-9]{1,}:commit:)(([a-zA-Z0-9]{1,}))/";
        preg_match_all( $regexp, $info, $matches, PREG_OFFSET_CAPTURE );
        return $matches[ 3 ][ 0 ][ 0 ];
    }

    protected function mergeCommits( $otherCommits ){
        if( !$otherCommits ){ return; }
        $output = array();
        for( $i = 0; $i < sizeof( $this->commits ); $i++ ){
            for( $ii = 0; $ii < sizeof( $otherCommits ); $ii++ ){
                if( $this->commits[ $i ]->isAfterThan( $otherCommits[ $ii ] ) ){
                    $output[] = array_shift( $this->commits );
                    $i--;
                    continue 2;
                }
                else if( $this->commits[ $i ]->hash == $otherCommits[ $ii ]->hash ){
                    array_shift( $otherCommits );
                    $ii--;
                }
                else{
                    $output[] = array_shift( $otherCommits );
                    $ii--;
                }
            }
        }
        $this->commits = array_merge( $output, $this->commits );
    }
}