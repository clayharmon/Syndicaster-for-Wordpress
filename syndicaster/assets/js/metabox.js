function syn_format(id, title, date, thumb, classes, parent, image){
  var output = "";
  output += "<div class='"+classes+"' title='"+title+"' data-id='"+id+"' data-parent='"+parent+"' data-image='"+image+"'>";
  output +=   "<span class='syn-x dashicons dashicons-dismiss'></span>";
  output +=   "<div class='syn-thumb'><img src='"+thumb+"'/></div>";
  output +=   "<div class='syn-meta'><span class='syn-title ellipsis'>"+title+"</span>";
  output += "<span class='syn-date'>"+date+"</span></div></div>";
  return output;
}

function syn_paging(current, total, perpage, is_playlist){
  total = (is_playlist) ? ((total > 1000) ? 1000 : total) : total;
  var total_pages = Math.ceil(total/perpage);
  console.log(is_playlist);
  var output ="";
  output += '<div class="tablenav-pages"><span class="pagination-links">';
  if(current == 1){
    output += '<span class="tablenav-pages-navspan" aria-hidden="true">«</span>';
    output += '<span class="tablenav-pages-navspan" aria-hidden="true">‹</span>';
  } else {
    output += '<a data-page="1" class="first-page" href="javascript:void(0);"><span aria-hidden="true">«</span></a>';
    output += '<a data-page="'+(parseInt(current)-1)+'" class="prev-page" href="javascript:void(0);"><span aria-hidden="true">‹</span></a>';
  }
  output += '<span style="margin: 0 5px;">'+current+'</span>';
  if(current == total_pages){
    output += '<span class="tablenav-pages-navspan" aria-hidden="true">›</span>';
    output += '<span class="tablenav-pages-navspan" aria-hidden="true">»</span>';
  } else {
    output += '<a data-page="'+(parseInt(current)+1)+'" class="next-page" href="javascript:void(0);"><span aria-hidden="true">›</span></a>';
    output += '<a data-page="'+(total_pages)+'" class="last-page" href="javascript:void(0);"><span aria-hidden="true">»</span></a>';
  }
  output += '</span></div>';

 return output;
}

jQuery(document).ready(function($) {
  var ajax;

  function syndicaster_post($term,$page){
    var $term = ($term)?$term:$('#syndicaster-search input').val();
    var $playlist = $('#syndicaster-playlist select').val();
    var $page = ($page)?$page:1;
    var data = {'action': 'syn_search','search': $term,'page':$page,'playlist':$playlist};
    $('#syndicaster-videos').css('opacity','0.2');
    $('#syndicaster-loader').css('display','block');
    ajax = $.post(obj.ajax_url, data, function(response) {
      var data = JSON.parse(response);
      var returns = data['returns'];
      var paging = data['paging'];

      console.log(paging);
      console.log(data);
      data = data['results'];
      if(data[0] === 'no_playlist'){
        $('#syndicaster-videos').css('opacity','1');
        $('#syndicaster-loader').css('display','none');

        return;
      }

      $('#syndicaster-videos').html('');
      if(data.length){
        for(var i = 0; i < data.length; i++){
          $('#syndicaster-videos').append(syn_format(data[i]['id'],data[i]['title'],data[i]['date'],data[i]['thumb'],'syn-video',data[i]['parent_id'],data[i]['image']));
        }
        var is_playlist = (returns['playlist'] == '' && returns['term'] == '') ? false : true;
        $('#syndicaster-pagination').html(syn_paging(paging['current'],paging['total_items'],paging['per_page'],is_playlist));
      } else {
        $('#syndicaster-videos').append("<div class='syn-novid'><span>No Results</span></div>");
      }
      $('#syndicaster-videos').css('opacity','1');
      $('#syndicaster-loader').css('display','none');
    });
  }
  syndicaster_post('',1);


  var syndicaster_timer;
  $('#syndicaster-search input').keyup(function(){
    if(ajax) { ajax.abort(); }
    syndicaster_timer && clearTimeout(syndicaster_timer);
    syndicaster_timer = setTimeout(syndicaster_post, 300);
  });

  $('#syndicaster-playlist select').on('change', function(){
    if(ajax) { ajax.abort(); }
    syndicaster_post();
  });

  $('#syndicaster-search input').keypress(function(e){
    if ( e.which == 13 ){return false;}
  });

  $('#syndicaster-pagination').on('click', 'a', function(){
    var search = $('#syndicaster-search input').val();
    var next_page = $(this).attr('data-page');
    syndicaster_post(search,next_page);

  });

  $('#syndicaster-attached').on('click', '.syn-x', function(){
    var chartlocal_field = $('input[name="news_story_video"]');
    var $file = $('input[name="syn_file_id"]');
    var $parent = $('input[name="syn_parent_id"]');
    var $image = $('input[name="syn_image_url"]');

    var $attached = "#syndicaster-attached";

    $($attached+' .syn-added').remove();
    $($attached).hide();
    chartlocal_field.attr('value', '');
    $file.attr('value','');
    $parent.attr('value','');
    $image.attr('value','');

  });

  $('#syndicaster-videos').on('click', '.syn-video', function(){
    //var thumb = "http://eplayer-static.clipsyndicate.com/thumbnails/lg/12434/"+$id+".jpg";
    var chartlocal_field = $('input[name="news_story_video"]');
    var $file = $('input[name="syn_file_id"]');
    var $parent = $('input[name="syn_parent_id"]');
    var $image = $('input[name="syn_image_url"]');
    //var featured_image_field = $('#featured_image_url');

    var $attached = "#syndicaster-attached";

    $($attached+' .syn-added').remove();
    $(this).clone().appendTo($attached);
    $($attached).find('.syn-video').removeClass('syn-video').addClass('syn-added');
    $($attached).show();
    var $file_id = $(this).attr('data-id');
    var $parent_id = $(this).attr('data-parent');
    var $image_url = $(this).attr('data-image')

    $file.attr('value',$file_id);
    $parent.attr('value',$parent_id);
    $image.attr('value',$image_url);

    if(chartlocal_field.length){
      chartlocal_field.attr('value', '');
    }
  });
});
