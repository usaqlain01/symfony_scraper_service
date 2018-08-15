symfony scraper service
=======================

A command line Symfony Service to scrape data from webpages and create content over drupal Rest API.

(todo version 2.0) Drush integration and autmation scripts.

Sample for command line

% php bin/console app:scrape http://www.usmanport.com [div that has the content in it] [limit of number of pages to parse]

as in 

% php bin/console app:scrape http://www.usmaport.com main 10

## Drupal(Scrape Data Storage) Setup (optional)

This application is configured to store data on Drupal CMS installation.

To Store data recieved from scrapping properly pleas make the following changes to your installation.

1. On content type "Article" add a file "url"(machine name: field_url) | Plain text, to make sure URL data is stored properly.
2. We are using default "Basic Html" text format which comes with default drupal installation. Make following edits on "configuration >>  Content authoring >> Text formats and editors"
* Text Editor | None
* Limit allowed HTML tags and correct faulty HTML | Check
* Convert line breaks into HTML | Check
* Convert URLs into links | Check
* Correct faulty and chopped off HTML | Check
* Uncheck remaining options checked by default in this section.


Note: Endpoints are designed for Drupal 8.2.X+
