/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * Bring the power of Elasticsearch to you Craft 3 CMS project
 *
 * @link      https://www.lahautesociete.com
 * @copyright Copyright (c) 2018 La Haute Société
 */

(
    function ($) {
      Craft.ElasticsearchUtility = Garnish.Base.extend({
        $trigger:          null,
        $form:             null,
        $innerProgressBar: null,

        formAction:       null,
        totalActions:     null,
        completedActions: null,
        loadingActions:   null,
        queue:            null,

        init: function (formId) {
          this.$form = $('#' + formId);
          this.$trigger = $('input.submit', this.$form);
          this.$status = $('.utility-status', this.$form);
          this.formAction = this.$form.attr('action');
          console.log(this.formAction);

          this.addListener(this.$form, 'submit', this.onSubmit);
        },

        onSubmit: function (ev) {
          ev.preventDefault();
          ev.stopPropagation();

          if (!this.$trigger.hasClass('disabled')) {
            if (!this.progressBar) {
              this.progressBar = new Craft.ProgressBar(this.$status);
            } else {
              this.progressBar.resetProgressBar();
            }

            this.totalActions = 1;
            this.completedActions = 0;
            this.queue = [];

            this.loadingActions = 0;
            this.currentEntryQueue = [];

            this.progressBar.$progressBar.css({
              top: Math.round(this.$status.outerHeight() / 2) - 6,
            }).removeClass('hidden');

            this.progressBar.$progressBar.velocity('stop').velocity(
                {
                  opacity: 1,
                },
                {
                  complete: $.proxy(function () {
                    var postData = Garnish.getPostData(this.$form);
                    var params = Craft.expandPostArray(postData);
                    params.start = true;

                    this.loadAction({
                      params: params,
                    });
                  }, this),
                },
            );

            if (this.$allDone) {
              this.$allDone.css('opacity', 0);
            }

            this.$trigger.addClass('disabled');
            this.$trigger.trigger('blur');
          }
        },

        updateProgressBar: function () {
          var width = (
              100 * this.completedActions / this.totalActions
          );
          this.progressBar.setProgressPercentage(width);
        },

        loadAction: function (data) {
          this.loadingActions++;
          this.postActionRequest(data.params);
        },

        postActionRequest: function (params) {
          var data = {
            params: params,
          };

          Craft.postActionRequest(
              this.formAction,
              data,
              $.proxy(this, 'onActionResponse'),
              {
                complete: $.noop,
              },
          );
        },

        onActionResponse: function (response, textStatus) {
          this.loadingActions--;
          this.completedActions++;

          // Add any new entries to the queue?
          if (textStatus === 'success' && response && response.entries) {
            for (var i = 0; i < response.entries.length; i++) {
              if (response.entries[ i ].length) {
                this.totalActions += response.entries[ i ].length;
                this.queue.push(response.entries[ i ]);
              }
            }
          }

          if (response && response.error) {
            alert(response.error);
          }

          this.updateProgressBar();

          // Load as many additional items in the current batch as possible
          while (this.loadingActions < Craft.ElasticsearchUtility.maxConcurrentActions &&
                 this.currentEntryQueue.length) {
            this.loadNextAction();
          }

          // Was that the end of the batch?
          if (!this.loadingActions) {
            // Is there another batch?
            if (this.queue.length > 0) {
              this.currentEntryQueue = this.queue.shift();
              this.loadNextAction();
            } else {
              // Quick delay so things don't look too crazy.
              setTimeout($.proxy(this, 'onComplete'), 300);
            }
          }
        },

        loadNextAction: function () {
          var data = this.currentEntryQueue.shift();
          this.loadAction(data);
        },

        onComplete: function () {
          if (!this.$allDone) {
            this.$allDone = $('<div class="alldone" data-icon="done" />').appendTo(this.$status);
            this.$allDone.css('opacity', 0);
          }

          this.progressBar.$progressBar.velocity({ opacity: 0 }, {
            duration: 'fast', complete: $.proxy(function () {
              this.$allDone.velocity({ opacity: 1 }, { duration: 'fast' });
              this.$trigger.removeClass('disabled');
              this.$trigger.trigger('focus');
            }, this),
          });
        },
      }, {
        maxConcurrentActions: 3,
      });
    }
)(jQuery);
