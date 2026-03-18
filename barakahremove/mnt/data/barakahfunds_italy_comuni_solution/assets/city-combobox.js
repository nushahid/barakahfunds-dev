(function(){
  function initCityCombobox(config){
    const searchInput=document.getElementById(config.searchInputId);
    const hiddenNameInput=document.getElementById(config.hiddenNameInputId);
    const hiddenIdInput=document.getElementById(config.hiddenIdInputId);
    const dropdown=document.getElementById(config.dropdownId);
    const list=document.getElementById(config.listId);
    const toggleBtn=document.getElementById(config.toggleBtnId);
    const combo=document.getElementById(config.comboId);
    const endpoint=config.endpoint;
    if(!searchInput||!hiddenNameInput||!hiddenIdInput||!dropdown||!list||!toggleBtn||!combo||!endpoint){return;}

    let activeIndex=-1;
    let items=[];
    let timer=null;

    function openDropdown(){dropdown.hidden=false;}
    function closeDropdown(){dropdown.hidden=true;activeIndex=-1;}
    function escapeHtml(v){return String(v||'').replace(/[&<>"']/g,function(s){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[s];});}
    function renderLoading(){list.innerHTML='<div class="city-loading">Searching...</div>';openDropdown();}
    function renderItems(){
      list.innerHTML='';
      if(!items.length){list.innerHTML='<div class="city-empty">No city found</div>';openDropdown();return;}
      items.forEach(function(item,index){
        const opt=document.createElement('div');
        opt.className='city-option';
        opt.dataset.index=String(index);
        opt.innerHTML='<span class="city-option-line">'+escapeHtml(item.comune_name)+'</span><span class="city-option-meta">'+escapeHtml((item.province_code||'')+(item.region_name?' • '+item.region_name:''))+'</span>';
        opt.addEventListener('click',function(){selectItem(item);});
        list.appendChild(opt);
      });
      openDropdown();
    }
    function selectItem(item){
      searchInput.value=item.comune_name||'';
      hiddenNameInput.value=item.comune_name||'';
      hiddenIdInput.value=item.id||'';
      closeDropdown();
    }
    function setActive(index){
      const options=list.querySelectorAll('.city-option');
      options.forEach(function(opt){opt.classList.remove('active');});
      if(index>=0&&options[index]){options[index].classList.add('active');options[index].scrollIntoView({block:'nearest'});}
    }
    function fetchItems(query){
      renderLoading();
      fetch(endpoint+'?q='+encodeURIComponent(query)+'&limit=10',{credentials:'same-origin'})
        .then(function(r){return r.json();})
        .then(function(data){items=(data&&data.items)||[];activeIndex=-1;renderItems();})
        .catch(function(){items=[];list.innerHTML='<div class="city-empty">Search error</div>';openDropdown();});
    }

    searchInput.addEventListener('input',function(){
      hiddenNameInput.value=searchInput.value.trim();
      hiddenIdInput.value='';
      const q=searchInput.value.trim();
      clearTimeout(timer);
      if(q.length<2){list.innerHTML='<div class="city-empty">Type at least 2 letters</div>';openDropdown();return;}
      timer=setTimeout(function(){fetchItems(q);},220);
    });

    searchInput.addEventListener('focus',function(){
      if(searchInput.value.trim().length>=2){fetchItems(searchInput.value.trim());}
    });

    searchInput.addEventListener('keydown',function(e){
      const options=list.querySelectorAll('.city-option');
      if(e.key==='ArrowDown'){e.preventDefault();activeIndex=Math.min(activeIndex+1,options.length-1);setActive(activeIndex);} 
      else if(e.key==='ArrowUp'){e.preventDefault();activeIndex=Math.max(activeIndex-1,0);setActive(activeIndex);} 
      else if(e.key==='Enter'){if(activeIndex>=0&&items[activeIndex]){e.preventDefault();selectItem(items[activeIndex]);}}
      else if(e.key==='Escape'){closeDropdown();}
    });

    toggleBtn.addEventListener('click',function(){
      if(dropdown.hidden){
        if(searchInput.value.trim().length>=2){fetchItems(searchInput.value.trim());}
        else{list.innerHTML='<div class="city-empty">Type at least 2 letters</div>';openDropdown();searchInput.focus();}
      }else{closeDropdown();}
    });

    document.addEventListener('click',function(e){if(!combo.contains(e.target)){closeDropdown();}});
  }
  window.initCityCombobox=initCityCombobox;
})();
