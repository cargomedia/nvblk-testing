(function() {
    var component = document.querySelector('script[src*=jsonfloat]');
    var config = {src: '', color: 'black', border: '1px', height: 'auto', width: 'auto', pos: {y: 'top', x: 'right'}}
    Object.keys(config).forEach(function(i) {
        var data = component.getAttribute('data-' + i);
        if (data) {
            config[i] = data;
        }
    })
    if (/^(top|bottom)\ (left|right)$/.test(component.dataset.position)) {
        let position = component.dataset.position.split(' ');
        config.pos.y = position[0];
        config.pos.x = position[1];
    } else if (component.dataset.position) {
        console.log("FLOAT AD POSITION ERROR: data-position='" + component.dataset.position + "' is invalid");
    }

    var mobileUrl = 'https://moartraffic.engine.adglare.net/?889006033'; //mobile ad zone URL (must be JSON)
    var desktopUrl = 'https://moartraffic.engine.adglare.net/?889006033'; //desktop ad zone URL (must be JSON)
    var url = window.innerWidth > 800 ? desktopUrl : mobileUrl;
    var ad = document.createElement('div');
    ad.className = 'float-ad';
    var styles = document.createElement('style');
    var css = document.createTextNode(`
div.float-ad {
box-sizing: border-box;
position: fixed;
z-index: 99999999;
${config.pos.x}: 1rem;
${config.pos.y}: 1.6rem;
transform: translate(-80vw);
opacity: 0;
transition: transform 500ms, opacity 400ms;
}
div.float-ad main.float-wrapper {
width: 100%;
max-width: 100%;
position: relative;
display: flex;
overflow: hidden;
border: none;
border-radius: 5px;
margin: 0;
}
div.float-ad > div {
background: ${config.color};
height: ${config.height};
width: ${config.width};
border-radius: 7px;
border: ${config.border} solid ${config.color};
}
div.float-ad .close-float {
background: ${translucifyColor(getRGB(config.color))};
border-radius: 5px;
position: absolute;
height: 2rem;
top: -0.3rem;
right: 0;
font-size: 1rem;
padding: 3px 3px;
cursor: pointer;
transform: translateY(-1.2rem);
color: ${getFontColor(getRGB(config.color))};
font-weight: 500;
}
@media screen and (min-width: 450px) {
div.float-ad {
right: 1rem;
transition: transform 1200ms, opacity 800ms;
}
}`)
    styles.appendChild(css);
    document.head.appendChild(styles);
    var fragment = document.createDocumentFragment();

    fetch(url).then(response => response.json()).then(data => {
        // console.log(data);
        switch (data.response.campaign.creative_type) {
            case "image":
                ad.innerHTML = `<div>
<div class="close-float" onclick="closeFloater()">Close</div>
<main class="float-wrapper">
<a href="${data.response.campaign.creative_data.click_url}">
<img src="${data.response.campaign.creative_data.image_url}">
</a>
</main>
</div>`
                break;
            case "thirdparty":
                ad.innerHTML = `<div>
<div class="close-float" onclick="closeFloater()">x</div>
<main class="float-wrapper">${data.response.campaign.creative_data.code}</main></div>`
                break;
            default:
                console.log("UNSUPPORTED FLOAT AD TYPE: " + data.response.campaign.creative_type);
        }

        return data
    }).then(data => {
        fragment.appendChild(ad);
        document.body.appendChild(fragment);
    }).then(data => {
        setTimeout(function() {
            document.querySelector("div.float-ad").style.transform = "translateX(-150vw)";
        }, 10);
        setTimeout(function() {
            document.querySelector("div.float-ad").style.opacity = 1;
        }, 500)
        setTimeout(function() {
            document.querySelector("div.float-ad").style.transform = "translateX(0)";
        }, 500)

    }).catch((error) => {
        console.log('ERROR IN FLOAT ZONE');
        console.log(error)
    })

    //Convert input color to RGB values
    function getRGB(colorStr) {
        var canvas = document.createElement('span');
        document.body.appendChild(canvas);
        canvas.style.color = colorStr;
        var bgRGB = window.getComputedStyle(canvas).color;
        document.body.removeChild(canvas);
        return bgRGB.substring(4, bgRGB.length - 1).split(', ');
    }

    //Check the 'Close-Tab' background color and choose a font color that has enough contrast
    //This function calculates relative luminance of background [https://en.wikipedia.org/wiki/Luma_(video),
    //https://stackoverflow.com/questions/596216/formula-to-determine-brightness-of-rgb-color]
    function getFontColor(rgb) {
        var luminance = 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
        // var luminance2 = Math.sqrt(0.299 * rgb[0]**2 + 0.587 * rgb[1]**2 + 0.114 * rgb[2]**2); //alternate method
        return luminance < 126 ? "white" : "black";
    }

    //Adjust background input color to be semi transparent
    function translucifyColor(rgb) {
        return `rgba(${rgb[0]}, ${rgb[1]}, ${rgb[2]}, 0.2)`;
    }
})()

//This globally scoped function is needed for closing the floater ad after it's injected
function closeFloater() {
    var floatAd = document.querySelector('div.float-ad').style;
    floatAd.transform = "translateX(100vw)";
    setTimeout(function() {
        floatAd.display = "none";
    }, 1000);
}
