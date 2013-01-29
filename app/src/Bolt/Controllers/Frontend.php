<?php

namespace Bolt\Controllers;

use Silex;
use Silex\ControllerProviderInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Frontend implements ControllerProviderInterface
{
    public function connect(Silex\Application $app)
    {
        $ctr = $app['controllers_factory'];

        $ctr->match("/", array($this, 'homepage'))
            ->before(array($this, 'before'))
            ->bind('homepage')
        ;

        $ctr->match('/search', array($this, 'search'))
            ->before(array($this, 'before'))
        ;

        $ctr->match('/sitemap', array($this, 'sitemap'))
            ->before(array($this, 'before'))
        ;

        $ctr->match('/sitemap.xml', array($this, 'sitemapXml'))
            ->before(array($this, 'before'))
        ;

        $ctr->match('/{contenttypeslug}/feed.{extension}', array($this, 'feed'))
            ->before(array($this, 'before'))
            ->assert('extension', '(xml|rss)')
            ->assert('contenttypeslug', $app['storage']->getContentTypeAssert())
        ;

        $ctr->match('/{contenttypeslug}/{slug}', array($this, 'record'))
            ->before(array($this, 'before'))
            ->assert('contenttypeslug', $app['storage']->getContentTypeAssert(true))
            ->bind('contentlink')
        ;

        $ctr->match('/{taxonomytype}/{slug}', array($this, 'taxonomy'))
            ->before(array($this, 'before'))
            ->assert('taxonomytype', $app['storage']->getTaxonomyTypeAssert(true))
            ->bind('taxonomylink')
        ;

        $ctr->match('/{contenttypeslug}', array($this, 'listing'))
            ->before(array($this, 'before'))
            ->assert('contenttypeslug', $app['storage']->getContentTypeAssert())
        ;

        return $ctr;
    }

    function before(Request $request, Silex\Application $app)
    {

        // If there are no users in the users table, or the table doesn't exist. Repair
        // the DB, and let's add a new user.
        if (!$app['storage']->checkUserTableIntegrity() || !$app['users']->getUsers()) {
            $app['session']->setFlash('info', "There are no users in the database. Please create the first user.");
            return redirect('useredit', array('id' => ""));
        }

        $app['debugbar'] = true;

    }

    function homepage(Silex\Application $app)
    {
        if (!empty($app['config']['general']['homepage_template'])) {
            $template = $app['config']['general']['homepage_template'];
            $content = $app['storage']->getContent($app['config']['general']['homepage']);
            $twigvars = array(
                'record' => $content,
                $content->contenttype['singular_slug'] => $content
            );
            $chosen = 'homepage config';
        } else {
            $template = 'index.twig';
            $twigvars = array();
            $chosen = 'homepage fallback';
        }

        $app['log']->setValue('templatechosen', $app['config']['general']['theme'] . "/$template ($chosen)");


        $body = $app['twig']->render($template, $twigvars);

        return new Response($body, 200, array('Cache-Control' => 's-maxage=3600, public'));

    }

    function record(Silex\Application $app, $contenttypeslug, $slug)
    {

        $contenttype = $app['storage']->getContentType($contenttypeslug);

        $slug = makeSlug($slug);

        // First, try to get it by slug.
        $content = $app['storage']->getContent($contenttype['slug'], array('slug' => $slug, 'returnsingle' => true));

        if (!$content && is_numeric($slug)) {
            // And otherwise try getting it by ID
            $content = $app['storage']->getContent($contenttype['slug'], array('id' => $slug, 'returnsingle' => true));
        }

        // No content, no page!
        if (!$content) {
            $app->abort(404, "Page $contenttypeslug/$slug not found.");
        }

        // Then, select which template to use, based on our 'cascading templates rules'
        $template = $content->template();

        // Fallback: If file is not OK, show an error page
        $filename = $app['paths']['themepath'] . "/" . $template;
        if (!file_exists($filename) || !is_readable($filename)) {
            $error = sprintf("No template for '%s' defined. Tried to use '%s/%s'.",
                $content->getTitle(),
                basename($app['config']['general']['theme']),
                $template);
            $app['log']->setValue('templateerror', $error);
            $app->abort(404, $error);
        }

        $app['editlink'] = path('editcontent', array('contenttypeslug' => $contenttypeslug, 'id' => $content->id));

        $body = $app['twig']->render($template, array(
            'record' => $content,
            $contenttype['singular_slug'] => $content // Make sure we can also access it as {{ page.title }} for pages, etc.
        ));

        return new Response($body, 200, array('Cache-Control' => 's-maxage=3600, public'));

    }


    function listing(Silex\Application $app, $contenttypeslug)
    {

        $contenttype = $app['storage']->getContentType($contenttypeslug);

        // First, get some content
        $page = $app['request']->query->get('page', 1);
        $amount = (!empty($contenttype['listing_records']) ? $contenttype['listing_records'] : $app['config']['general']['listing_records']);
        $order = (!empty($contenttype['sort']) ? $contenttype['sort'] : $app['config']['general']['listing_sort']);
        $content = $app['storage']->getContent($contenttype['slug'], array('limit' => $amount, 'order' => $order, 'page' => $page));

        if (!$content) {
            $app->abort(404, "Content for '$contenttypeslug' not found.");
        }

        // Then, select which template to use, based on our 'cascading templates rules'
        if (!empty($contenttype['listing_template'])) {
            $template = $contenttype['listing_template'];
            $chosen = "contenttype";
        } else {
            $filename = $app['paths']['themepath'] . "/" . $contenttype['slug'] . ".twig";
            if (file_exists($filename) && is_readable($filename)) {
                $template = $contenttype['slug'] . ".twig";
                $chosen = "slug";
            } else {
                $template = $app['config']['general']['listing_template'];
                $chosen = "config";

            }
        }

        $app['log']->setValue('templatechosen', $app['config']['general']['theme'] . "/$template ($chosen)");


        // Fallback: If file is not OK, show an error page
        $filename = $app['paths']['themepath'] . "/" . $template;
        if (!file_exists($filename) || !is_readable($filename)) {
            $error = sprintf("No template for '%s'-listing defined. Tried to use '%s/%s'.",
                $contenttypeslug,
                basename($app['config']['general']['theme']),
                $template);
            $app['log']->setValue('templateerror', $error);
            $app->abort(404, $error);
        }

        // $app['editlink'] = path('editcontent', array('contenttypeslug' => $contenttypeslug, 'id' => $content->id));

        $body = $app['twig']->render($template, array(
            'records' => $content,
            $contenttype['slug'] => $content // Make sure we can also access it as {{ pages }} for pages, etc.
        ));

        return new Response($body, 200, array('Cache-Control' => 's-maxage=3600, public'));

    }


    function taxonomy(Silex\Application $app, $taxonomytype, $slug)
    {

        // First, get some content
        $page = $app['request']->query->get('page', 1);
        $amount = $app['config']['general']['listing_records'];
        $order = $app['config']['general']['listing_sort'];
        $content = $app['storage']->getContentByTaxonomy($taxonomytype, $slug, array('limit' => $amount, 'order' => $order, 'page' => $page));

        if (!$content) {
            $app->abort(404, "Content for '$taxonomytype/$slug' not found.");
        }

        $template = $app['config']['general']['listing_template'];
        $chosen = "taxonomy";

        $app['log']->setValue('templatechosen', $app['config']['general']['theme'] . "/$template ($chosen)");


        // Fallback: If file is not OK, show an error page
        $filename = $app['paths']['themepath'] . "/" . $template;
        if (!file_exists($filename) || !is_readable($filename)) {
            $error = sprintf("No template for '%s'-listing defined. Tried to use '%s/%s'.",
                $contenttypeslug,
                basename($app['config']['general']['theme']),
                $template);
            $app['log']->setValue('templateerror', $error);
            $app->abort(404, $error);
        }

        // $app['editlink'] = path('editcontent', array('contenttypeslug' => $contenttypeslug, 'id' => $content->id));

        $body = $app['twig']->render($template, array(
            'records' => $content
        ));

        return new Response($body, 200, array('Cache-Control' => 's-maxage=3600, public'));

    }


    public function feed(Silex\Application $app, $contenttypeslug)
    {
        // Clear the snippet queue
        $app['extensions']->clearSnippetQueue();
        $app['extensions']->disableJquery();
        $app['debugbar'] = false;

        $contenttype = $app['storage']->getContentType($contenttypeslug);

        if (!isset($contenttype['rss']['enabled']) ||
            $contenttype['rss']['enabled'] != 'true'
        ) {
            $app->abort(404, "Feed for '$contenttypeslug' not found.");
        }

        // Better safe than sorry: abs to prevent negative values
        $amount = (int) abs((!empty($contenttype['rss']['feed_records']) ?
            $contenttype['rss']['feed_records'] :
            $app['config']['rss']['feed_records']));
        // How much to display in the description. Value of 0 means full body!
        $contentLength = (int) abs(
            (!empty($contenttype['rss']['content_length']) ?
                $contenttype['rss']['content_length'] :
                $app['config']['rss']['content_length'])
        );

        $content = $app['storage']->getContent(
            $contenttype['slug'],
            array('limit' => $amount, 'order' => 'datepublish desc')
        );

        if (!$content) {
            $app->abort(404, "Feed for '$contenttypeslug' not found.");
        }

        // Then, select which template to use, based on our
        // 'cascading templates rules'
        if (!empty($contenttype['rss']['feed_template'])) {
            $template = $contenttype['rss']['feed_template'];
        } else if (!empty($app['config']['rss']['feed_template'])) {
            $template = $app['config']['rss']['feed_template'];
        } else {
            $template = 'rss.twig';
        }

        $body = $app['twig']->render($template, array(
            'records' => $content,
            'content_length' => $contentLength,
            $contenttype['slug'] => $content,
        ));

        return new Response($body, 200,
            array('Content-Type' => 'application/rss+xml; charset=utf-8',
                'Cache-Control' => 's-maxage=3600, public',
            )
        );
    }

    public function search(Request $request, Silex\Application $app)
    {
        //$searchterms =  safeString($request->get('search'));
        $template = (!empty($app['config']['general']['search_results_template'])) ? $app['config']['general']['search_results_template'] : $app['config']['general']['listing_template'] ;

        // @todo Preparation for stage 2
        //$resultsPP = (int) $app['config']['general']['search_results_records'];
        //$page = (!empty($_GET['page']) ? $_GET['page'] : 1);

        //$parameters = array('limit' => $resultsPP, 'page' => $page, 'filter' => $request->get('search'));
        $parameters = array('filter' => $request->get('search'));

        //$content = $searchterms . " and " . $resultsPP;
        $content = $app['storage']->searchAllContentTypes($parameters);
        //$content = $app['storage']->searchContentType('entries', $searchterms, $parameters);

        $body = $app['twig']->render($template, array(
            'records' => $content
        ));

        return new Response($body, 200, array('Cache-Control' => 's-maxage=3600, public'));
    }

    public function sitemap(Silex\Application $app, $xml = false)
    {
        if($xml){
            $app['extensions']->clearSnippetQueue();
            $app['extensions']->disableJquery();
            $app['debugbar'] = false;
        }

        $links = array(array('link' => $app['paths']['root'], 'title' => $app['config']['general']['sitename']));
        foreach( $app['config']['contenttypes'] as $contenttype ) {
            if (isset($contenttype['listing_template'])) {
                $links[] = array( 'link' => $app['paths']['root'].$contenttype['slug'], 'title' => $contenttype['name'] );
            }
            if (isset($contenttype['record_template'])) {
                $content = $app['storage']->getContent($contenttype['slug']);
                foreach( $content as $entry ) {
                    $links[] = array('link' => $entry->link(), 'title' => $entry->getTitle(),
                        'lastmod' => date( \DateTime::W3C, strtotime($entry->get('datechanged'))));
                }
            }
        }
        if ($xml) {
            $template = $app['config']['general']['sitemap_xml_template'];
        } else {
            $template = $app['config']['general']['sitemap_template'];
        }

        $body = $app['twig']->render($template, array(
            'entries' => $links
        ));
        $headers = array();
        if ($xml) {
            $headers['Content-Type'] = 'application/xml; charset=utf-8';
        }
        $headers['Cache-Control'] = 's-maxage=3600, public';

        return new Response($body, 200, $headers);
    }

    public function sitemapXml(Silex\Application $app)
    {
        return $this->sitemap($app,true);
    }


}
