var newsletterAdmin = angular.module('newsletterAdmin', ['ui.bootstrap']);

newsletterAdmin.controller('newsletterAdminCtrl', ['$scope', '$timeout',
    function( $scope, $timeout ) {
        
        $ = jQuery;

        $scope.main = _main;
        $scope.showLoading = false;

        $scope.sendNewsletter = function() {
            if ( $('#post input[name=bf_newsletter_test_addresses]').val() === "" ) {
                if ( ! confirm( 'Are you sure?  This will send to all recipients!' ) ) return;
            }
            $('#post input[name=bf_newsletter_send_newsletter]').val('1');
            var data = $('#post').serializeArray();
            $scope.email = {total:1, count:0};
            $scope.sending = true;
            $scope.showLoading = true;
            $.post( $scope.main.post_url, data, function( response ) {
                $scope.showLoading = false;
                var ajaxdata = $.parseJSON( response ); return;
                $timeout.cancel ( $scope.progress );
                $scope.sending = false;
                $scope.showProgressMessage = true;
                $scope.email = $scope.email || {};
                $scope.showProgressNumber = false;
                $scope.email.message = ajaxdata.success;
                $scope.$apply();
                $('#post input[name=bf_newsletter_send_newsletter]').val('0');
            });
            /* progress */
            $scope.progress = $timeout ( $scope.displayProgress, 1000 );
        };
        
        $scope.displayProgress = function() {
            data = {'action':'bf_newsletter_progress', 'post_id':$('#post input[name=ajax_id]').val() };
            $.post ( $scope.main.ajax_url, data, function ( response ) {
                $scope.showLoading = false;
                $scope.$apply();
                $scope.email = $.parseJSON ( response );
                if($scope.sending) {
                    $scope.showProgressNumber = true;
                    $scope.$apply();
                    $scope.progress = $timeout ( $scope.displayProgress, 1000 );
                }
            });
        };
        
    }
]);