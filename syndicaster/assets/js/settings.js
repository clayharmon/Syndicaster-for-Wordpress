jQuery(document).ready(function($) {

  function syndicaster_post(command, stuff = []){
    $('.form-table').css('opacity','0.4');
    $('#syndicaster-loader').css('display','block');
    var data = {'action': 'syn_settings','syn_command' : command, 'syn_data' : stuff};
    $.post(obj.ajax_url, data, function(response){
      $('.form-table').css('opacity','1');
      $('#syndicaster-loader').css('display','none');
      data = JSON.parse(response);
      if(data.status == 'success'){
        window.location.reload(false);
      }
    });

  }

  $('.syn-auth').on('click','.syn-deauthorize', function(){
    syndicaster_post('deauthorize');
  });

  $('.syn-auth').on('click','.syn-authorize', function(){
    var $inputs = $('.syn-app input');
    var is_empty = false;
    var data = {};
    $inputs.each(function(index){
      var id = $(this).attr('id');
      var val = $(this).val();
      data[id] = val;
      if($(this).val() == '') { is_empty = true; }
    });
    if(is_empty){ return false; }
    syndicaster_post('authorize',data);
  });
  $('.syn-auth').on('click','.syn-update', function(){
    var $fields = $('.syn-account select');
    if($fields.val() == ''){ return false; }
    var publisher = $fields.val();
    var owner = $fields.find(':selected').attr('data-owner');
    var id = $fields.attr('id');
    data = {};
    data[id] = [publisher, owner];

    console.log(data);
    syndicaster_post('update',data);
  });
});
