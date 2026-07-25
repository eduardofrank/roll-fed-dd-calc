<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function fac_get_default_paper_data() {
    return array(

        'Hahnemühle' => array(

            'Matt Smooth' => array(
                array( 'name' => 'Photo Rag',              'slug' => 'photo_rag',              'colIndex' => 1, 'rate' => 0.0414, 'gsm' => 308, 'availableRolls' => array('44','50','60'),     'description' => '100% white cotton paper with a smooth surface texture and matt finish.',                      'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Photo-Rag.jpg' ),
                array( 'name' => 'Photo Rag Ultra Smooth', 'slug' => 'photo_rag_ultra_smooth', 'colIndex' => 2, 'rate' => 0.0414, 'gsm' => 305, 'availableRolls' => array('44','50','60','64'), 'description' => 'Extra smooth felt structure for detailed fine art applications.',                              'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Photo-Rag-Ultra-Smooth.jpg' ),
                array( 'name' => 'Photo Rag Bright White', 'slug' => 'photo_rag_bright_white', 'colIndex' => 3, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44','50','60'),     'description' => 'Bright white paper for high-contrast images and deep blacks.',                                'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Photo-Rag-BW.jpg' ),
                array( 'name' => 'Rice Paper',             'slug' => 'rice_paper',             'colIndex' => 4, 'rate' => 0.0414, 'gsm' => 100, 'availableRolls' => array('44'),               'description' => 'Ultra-lightweight cellulose paper with traditional laid texture.',                             'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Rice-Paper.jpg' ),
            ),

            'Matt Textured' => array(
                array( 'name' => 'Agave',          'slug' => 'agave',          'colIndex' => 1, 'rate' => 0.0414, 'gsm' => 290, 'availableRolls' => array('44'),           'description' => 'Eco-friendly paper made from agave fibers with a lovely rough texture.',                         'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Agave.jpg' ),
                array( 'name' => 'Hemp',           'slug' => 'hemp',           'colIndex' => 2, 'rate' => 0.0414, 'gsm' => 290, 'availableRolls' => array('44'),           'description' => 'Sustainable organic hemp fibers offering high durability and natural tone.',                     'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Hemp.jpg' ),
                array( 'name' => 'German Etching', 'slug' => 'german_etching', 'colIndex' => 3, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44','50','60'), 'description' => 'Classic heavy copperplate printing board structure, deep textured.',                            'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/German-Etching.jpg' ),
                array( 'name' => 'William Turner', 'slug' => 'william_turner', 'colIndex' => 4, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44'),           'description' => 'Genuine mould-made paper with a highly pronounced watercolor textured finish.',                   'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/William-Turner.jpg' ),
            ),

            'Glossy' => array(
                array( 'name' => 'FineArt Baryta Satin', 'slug' => 'fineart_baryta_satin', 'colIndex' => 1, 'rate' => 0.0414, 'gsm' => 300, 'availableRolls' => array('44','50','60'),     'description' => 'Satin gloss baryta paper with brilliant color reproduction and detail.',                 'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/FineArt-Baryta-Satin.jpg' ),
                array( 'name' => 'Photo Rag Satin',      'slug' => 'photo_rag_satin',      'colIndex' => 2, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44'),               'description' => 'Unique satin surface effect where printed areas glow with elegant luster.',              'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Photo-Rag-Satin.jpg' ),
                array( 'name' => 'Photo Rag Baryta',     'slug' => 'photo_rag_baryta',     'colIndex' => 3, 'rate' => 0.0414, 'gsm' => 315, 'availableRolls' => array('44','50','60','64'), 'description' => 'Exquisite combination of smooth cotton and baryta gloss.',                              'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Photo-Rag-Baryta.jpg' ),
                array( 'name' => 'FineArt Baryta',       'slug' => 'fineart_baryta',       'colIndex' => 4, 'rate' => 0.0414, 'gsm' => 325, 'availableRolls' => array('44','50','60'),     'description' => 'Traditional high-gloss darkroom aesthetic with barium sulfate coating.',                'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/FineArt-Baryta.jpg' ),
                array( 'name' => 'Photo Rag Metallic',   'slug' => 'photo_rag_metallic',   'colIndex' => 5, 'rate' => 0.0432, 'gsm' => 340, 'availableRolls' => array('44','50'),           'description' => 'Unforgettable silver-metallic glossy finish for futuristic and high-contrast designs.', 'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Phota-Rag-Metallic.jpg' ),
            ),

            'Canvas' => array(
                array( 'name' => 'Cézanne Canvas',  'slug' => 'cezanne_canvas',  'colIndex' => 1, 'rate' => 0.0414, 'gsm' => 430, 'availableRolls' => array('44','60'), 'description' => 'Heavy canvas with a natural white, fine surface grain under high-grade matt coat.',  'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Cezanne-Canvas.jpg' ),
                array( 'name' => 'Goya Canvas',     'slug' => 'goya_canvas',     'colIndex' => 2, 'rate' => 0.0414, 'gsm' => 340, 'availableRolls' => array('44','60'), 'description' => 'Slightly textured poly-cotton canvas for impressive canvas prints.',                'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Goya-Canvas.jpg' ),
                array( 'name' => 'Daguerre Canvas', 'slug' => 'daguerre_canvas', 'colIndex' => 3, 'rate' => 0.0414, 'gsm' => 400, 'availableRolls' => array('44','60'), 'description' => 'Bright white canvas optimized for extreme color depth and contrast.',              'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Daguerre-Canvas.jpg' ),
                array( 'name' => 'Canvas Metallic', 'slug' => 'canvas_metallic', 'colIndex' => 4, 'rate' => 0.0432, 'gsm' => 350, 'availableRolls' => array('44','60'), 'description' => 'Sparkling silver-metallic finish on premium textured canvas cloth.',               'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Canvas-Metallic.jpg' ),
            ),
        ),

        'Canson Infinity' => array(

            'Matt Smooth' => array(
                array( 'name' => 'Baryta Photographique II Matt', 'slug' => 'baryta_photographique_ii_matt', 'colIndex' => 1, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44'),       'description' => 'Rich darkroom texture in a beautiful non-reflective matt baryta coating.', 'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Baryta-Phographique-II-Matt.jpg' ),
                array( 'name' => 'Rag Photographique 310',        'slug' => 'rag_photographique_310',        'colIndex' => 2, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44'),       'description' => 'Ultra-smooth museum-grade white rag paper with excellent longevity.',       'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Rag-Photographique-310.jpg' ),
                array( 'name' => 'Arches 88',                     'slug' => 'arches_88',                     'colIndex' => 3, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44'),       'description' => '100% white cotton cylinder-mould paper with absolute flat finish.',         'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Arches-88.jpg' ),
            ),

            'Matt Textured' => array(
                array( 'name' => 'Arches BFK Rives White',      'slug' => 'arches_bfk_rives_white',      'colIndex' => 1, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44'),       'description' => 'Luxe French paper with a soft velvet grain and balanced white point.',          'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Arches-BFK-Rives-White.jpg' ),
                array( 'name' => 'Arches BFK Rives Pure White', 'slug' => 'arches_bfk_rives_pure_white', 'colIndex' => 2, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44'),       'description' => 'Pure white tone paired with gorgeous heritage BFK textured grain.',             'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Arches-BFK-Rives-Pure-White.jpg' ),
                array( 'name' => 'Edition Etching Rag',         'slug' => 'edition_etching_rag',         'colIndex' => 3, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44','60'),   'description' => 'Beautiful soft grain mimicking traditional copperplate etching papers.',         'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Edition-Etching-Rag.jpg' ),
                array( 'name' => 'Aquarelle Rag 310',           'slug' => 'aquarelle_rag_310',           'colIndex' => 4, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44'),       'description' => 'Authentic rich watercolor textured surface with high dMax.',                     'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Aquarelle-Rag-310.jpg' ),
            ),

            'Glossy' => array(
                array( 'name' => 'Baryta Photographique II', 'slug' => 'baryta_photographique_ii', 'colIndex' => 1, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44','50','60'), 'description' => 'Ultra-saturated classic darkroom luster with true barium sulfate.',            'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Baryta-Photographique-II.jpg' ),
                array( 'name' => 'Platine Fibre Rag',        'slug' => 'platine_fibre_rag',        'colIndex' => 2, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44','60'),     'description' => 'High-density platinum printing aesthetic on pure long-staple cotton.',         'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Platine-Fibre-Rag.jpg' ),
                array( 'name' => 'Baryta Prestige II',       'slug' => 'baryta_prestige_ii',       'colIndex' => 3, 'rate' => 0.0414, 'gsm' => 340, 'availableRolls' => array('44'),           'description' => 'Stately alpha-cellulose and cotton blend, exceptionally heavy gloss.',         'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Baryta-Prestige-II.jpg' ),
            ),

            'Canvas' => array(
                array( 'name' => 'PhotoArt Pro Canvas Matte 395',  'slug' => 'photoart_pro_canvas_matte',        'colIndex' => 1, 'rate' => 0.0414, 'gsm' => 395, 'availableRolls' => array('44'),       'description' => 'Professional matt coating with high definition weave structure.',                  'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/PhotoArt-Pro-Canvas-Matte-395.jpg' ),
                array( 'name' => 'Museum Pro Canvas Matte',         'slug' => 'museum_pro_canvas_matte',          'colIndex' => 2, 'rate' => 0.0414, 'gsm' => 385, 'availableRolls' => array('44','60'),   'description' => 'Heavy natural white archival canvas without optical brighteners.',                  'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Museum-Art-Pro-Canvas-Matte.jpg' ),
                array( 'name' => 'PhotoArt Pro Canvas Lustre 395',  'slug' => 'photoart_pro_canvas_lustre',       'colIndex' => 3, 'rate' => 0.0414, 'gsm' => 395, 'availableRolls' => array('44'),       'description' => 'Satin-gloss finish optimized for extreme vibrancy and contrast.',                  'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/PhotoArt-Pro-Canvas-Lustre-395.jpg' ),
                array( 'name' => 'Museum Pro Canvas Lustre',        'slug' => 'museum_pro_canvas_lustre_canson',  'colIndex' => 4, 'rate' => 0.0414, 'gsm' => 385, 'availableRolls' => array('44','60'),   'description' => 'Premium museum-grade luster coat with structured water resistant fibers.',         'imageUrl' => 'https://artmedia.studio/wp-content/uploads/2023/03/Museum-Art-Pro-Canvas-Lustre.jpg' ),
            ),
        ),

        'ILFORD' => array(

            'Matt Smooth' => array(
                array( 'name' => 'Smooth Cotton Rag',    'slug' => 'smooth_cotton_rag',    'colIndex' => 1, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44','50','60','64'), 'description' => 'Slightly warm white cotton base, perfect for portrait and wedding art.' ),
                array( 'name' => 'Smooth Cotton Sonora', 'slug' => 'smooth_cotton_sonora', 'colIndex' => 2, 'rate' => 0.0414, 'gsm' => 320, 'availableRolls' => array('44','50','60'),     'description' => 'Fine art smooth cotton with high-capacity ink-absorbing layer.' ),
                array( 'name' => 'Smooth Cotton Sprite', 'slug' => 'smooth_cotton_sprite', 'colIndex' => 3, 'rate' => 0.0414, 'gsm' => 270, 'availableRolls' => array('44'),               'description' => 'Highly affordable fine art light cotton paper for general proofing.' ),
                array( 'name' => 'Fine Art Smooth Pearl','slug' => 'fine_art_smooth_pearl','colIndex' => 4, 'rate' => 0.0414, 'gsm' => 270, 'availableRolls' => array('44'),               'description' => 'Delicate pearl sheen on highly stable alpha-cellulose core.' ),
            ),

            'Matt Textured' => array(
                array( 'name' => 'Textured Cotton Rag',    'slug' => 'textured_cotton_rag',    'colIndex' => 1, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44','50','60','64'), 'description' => 'Stunning highly textured rag surface indicating artisanal design craft.' ),
                array( 'name' => 'Cotton Artist Textured', 'slug' => 'cotton_artist_textured', 'colIndex' => 2, 'rate' => 0.0414, 'gsm' => 290, 'availableRolls' => array('44','50','60','64'), 'description' => 'Traditional heavy-textured paper mimicking handmade sketch sheets.' ),
                array( 'name' => 'Matt Cotton Medina',     'slug' => 'matt_cotton_medina',     'colIndex' => 3, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44','50','60'),     'description' => 'Exceptional texture response with broad gamut support.' ),
                array( 'name' => 'Textured Cotton Sprite', 'slug' => 'textured_cotton_sprite', 'colIndex' => 4, 'rate' => 0.0414, 'gsm' => 270, 'availableRolls' => array('44'),               'description' => 'Intermediate textured cotton paper offering robust durability.' ),
                array( 'name' => 'Fine Art Textured Silk', 'slug' => 'fine_art_textured_silk', 'colIndex' => 5, 'rate' => 0.0414, 'gsm' => 270, 'availableRolls' => array('44'),               'description' => 'Silky warm-toned textured surface coating.' ),
                array( 'name' => 'Washi Torinoko',         'slug' => 'washi_torinoko',         'colIndex' => 6, 'rate' => 0.0414, 'gsm' => 110, 'availableRolls' => array('44'),               'description' => 'Exotic Japanese mulberry washi paper with unique fiber structure.' ),
            ),

            'Glossy' => array(
                array( 'name' => 'Gold Fibre Gloss', 'slug' => 'gold_fibre_gloss', 'colIndex' => 1, 'rate' => 0.0414, 'gsm' => 310, 'availableRolls' => array('44','50','60','64'), 'description' => 'Semi-gloss baryta with brilliant contrast, deep archival suitability.' ),
                array( 'name' => 'Gold Fibre Pearl', 'slug' => 'gold_fibre_pearl', 'colIndex' => 2, 'rate' => 0.0414, 'gsm' => 290, 'availableRolls' => array('44'),               'description' => 'High definition luster baryta with wide gamut for color details.' ),
                array( 'name' => 'Gold Fibre Rag',   'slug' => 'gold_fibre_rag',   'colIndex' => 3, 'rate' => 0.0414, 'gsm' => 270, 'availableRolls' => array('44','50','60','64'), 'description' => 'Traditional thick gloss format for ultimate photo exhibition.' ),
            ),

            'Canvas' => array(
                array( 'name' => 'Fine Art Canvas Galicia',   'slug' => 'fine_art_canvas_galicia',   'colIndex' => 1, 'rate' => 0.0414, 'gsm' => 450, 'availableRolls' => array('44','60'), 'description' => 'Ultra-heavy-duty cotton canvas for framing stretchers.' ),
                array( 'name' => 'Decor Canvas Matt Cotton',  'slug' => 'decor_canvas_matt_cotton',  'colIndex' => 2, 'rate' => 0.0414, 'gsm' => 380, 'availableRolls' => array('44'),       'description' => 'Professional matt cotton designed for precise replica artwork.' ),
                array( 'name' => 'Decor Canvas Bright White', 'slug' => 'decor_canvas_bright_white', 'colIndex' => 3, 'rate' => 0.0414, 'gsm' => 370, 'availableRolls' => array('44'),       'description' => 'Bright white woven canvas for high contrast pop arts.' ),
                array( 'name' => 'Decor Canvas Glossy',       'slug' => 'decor_canvas_glossy',       'colIndex' => 4, 'rate' => 0.0414, 'gsm' => 390, 'availableRolls' => array('44'),       'description' => 'Lustrous high-gloss woven fabric ready for dynamic layouts.' ),
            ),
        ),

        'Awagami' => array(

            'Matt Fine Textured' => array(
                array( 'name' => 'Murakumo Kozo 42 gsm', 'slug' => 'murakumo_kozo_42_gsm', 'colIndex' => 1, 'rate' => 0.0414, 'gsm' => 42,  'availableRolls' => array('44'), 'description' => 'Highly delicate organic mulberry paper for ethereal overlays.' ),
                array( 'name' => 'Inbe Thin 70 gsm',     'slug' => 'inbe_thin_70_gsm',     'colIndex' => 2, 'rate' => 0.0414, 'gsm' => 70,  'availableRolls' => array('44'), 'description' => 'Ultra-thin, resilient mulberry fiber paper with crisp detail.' ),
                array( 'name' => 'Bamboo Paper 110 gsm', 'slug' => 'bamboo_paper_110_gsm', 'colIndex' => 3, 'rate' => 0.0414, 'gsm' => 110, 'availableRolls' => array('44'), 'description' => 'Organic bamboo fibers with deep matte characteristics.' ),
                array( 'name' => 'Bamboo Paper 170 gsm', 'slug' => 'bamboo_paper_170_gsm', 'colIndex' => 4, 'rate' => 0.0414, 'gsm' => 170, 'availableRolls' => array('44'), 'description' => 'Thicker organic bamboo stock with soft, natural texture.' ),
            ),
        ),
    );
}

function fac_get_default_roll_widths() {
    return array(
        array( 'key' => '44', 'label' => '44" Roll (43.7" / 111 cm usable)',   'widthInches' => 44, 'usableInches' => 43.7, 'usableCm' => 111 ),
        array( 'key' => '50', 'label' => '50" Roll (49.7" / 126.2 cm usable)', 'widthInches' => 50, 'usableInches' => 49.7, 'usableCm' => 126.238 ),
        array( 'key' => '60', 'label' => '60" Roll (59.7" / 151.6 cm usable)', 'widthInches' => 60, 'usableInches' => 59.7, 'usableCm' => 151.638 ),
        array( 'key' => '64', 'label' => '64" Roll (63.7" / 161.8 cm usable)', 'widthInches' => 64, 'usableInches' => 63.7, 'usableCm' => 161.798 ),
    );
}

function fac_get_default_mounting_rates() {
    return array(
        'inches' => array(
            'no_mounting'      => 0,
            'white_gatorboard' => 0.0694,
            'black_gatorboard' => 0.0833,
        ),
        'centimeters' => array(
            'no_mounting'      => 0,
            'white_gatorboard' => 0.0108,
            'black_gatorboard' => 0.013,
        ),
    );
}

function fac_get_default_turnaround_rates() {
    return array(
        'standard' => 1,
        'rush'     => 1.15,
    );
}

function fac_get_default_inkjet_paper_data() {
    $all_rolls = array( '44', '50', '60', '64' );
    $rate      = 0.0414;

    return array(
        array(
            'name'           => 'ArtDeco 17 Mil High Resolution Water Resistant Gloss Canvas',
            'slug'           => 'artdeco_17_mil_high_resolution_water_resistant_gloss_canvas',
            'category'       => 'canvas',
            'rate'           => $rate,
            'gsm'            => 0,
            'availableRolls' => $all_rolls,
            'description'    => 'High-resolution water-resistant gloss canvas, 17 mil.',
        ),
        array(
            'name'           => 'ArtDeco 22 Mil PolyCotton Water-Resistant Matte Canvas',
            'slug'           => 'artdeco_22_mil_polycotton_water_resistant_matte_canvas',
            'category'       => 'canvas',
            'rate'           => $rate,
            'gsm'            => 0,
            'availableRolls' => $all_rolls,
            'description'    => 'Poly-cotton water-resistant matte canvas, 22 mil.',
        ),
        array(
            'name'           => 'ArtDeco 22.5 Mil Canvas Metallic Pearl',
            'slug'           => 'artdeco_22_5_mil_canvas_metallic_pearl',
            'category'       => 'canvas',
            'rate'           => $rate,
            'gsm'            => 0,
            'availableRolls' => $all_rolls,
            'description'    => 'Metallic pearl canvas, 22.5 mil.',
        ),
        array(
            'name'           => 'ArtDeco 310g Velvet Textured Bright White Matte',
            'slug'           => 'artdeco_310g_velvet_textured_bright_white_matte',
            'category'       => 'papers',
            'rate'           => $rate,
            'gsm'            => 310,
            'availableRolls' => $all_rolls,
            'description'    => 'Velvet textured bright white matte paper. Wt: 310 GSM',
        ),
        array(
            'name'           => 'ArtDeco 8 Mil Universal Gloss Photo Paper',
            'slug'           => 'artdeco_8_mil_universal_gloss_photo_paper',
            'category'       => 'papers',
            'rate'           => $rate,
            'gsm'            => 0,
            'availableRolls' => $all_rolls,
            'description'    => 'Universal gloss photo paper, 8 mil.',
        ),
        array(
            'name'           => 'Epson Enhanced Matte Inkjet Paper 192 gsm',
            'slug'           => 'epson_enhanced_matte_inkjet_paper_192',
            'category'       => 'papers',
            'rate'           => $rate,
            'gsm'            => 192,
            'availableRolls' => $all_rolls,
            'description'    => 'Enhanced matte inkjet photo paper. Wt: 192 GSM',
        ),
        array(
            'name'           => 'Epson Metallic Photo Paper Glossy 257 gsm',
            'slug'           => 'epson_metallic_photo_paper_glossy_257',
            'category'       => 'papers',
            'rate'           => $rate,
            'gsm'            => 257,
            'availableRolls' => $all_rolls,
            'description'    => 'Metallic glossy photo paper. Wt: 257 GSM',
        ),
        array(
            'name'           => 'Epson Premium Luster Photo 260 gsm',
            'slug'           => 'epson_premium_luster_photo_260',
            'category'       => 'papers',
            'rate'           => $rate,
            'gsm'            => 260,
            'availableRolls' => $all_rolls,
            'description'    => 'Premium luster photo paper. Wt: 260 GSM',
        ),
        array(
            'name'           => 'Sihl 3148 Absolute Clear Film With Interleaf Paper',
            'slug'           => 'sihl_3148_absolute_clear_film_with_interleaf_paper',
            'category'       => 'other',
            'rate'           => $rate,
            'gsm'            => 0,
            'availableRolls' => $all_rolls,
            'description'    => 'Absolute clear film with interleaf paper.',
        ),
        array(
            'name'           => 'Sihl 3209 QuickSTICK Aqueous Adhesive Backed Fabric',
            'slug'           => 'sihl_3209_quickstick_aqueous_adhesive_backed_fabric',
            'category'       => 'vinyl_fabric',
            'rate'           => $rate,
            'gsm'            => 0,
            'availableRolls' => $all_rolls,
            'description'    => 'Aqueous adhesive backed fabric.',
        ),
        array(
            'name'           => 'Sihl 3585 Premium Vinyl SA 270 Gloss',
            'slug'           => 'sihl_3585_premium_vinyl_sa_270_gloss',
            'category'       => 'vinyl_fabric',
            'rate'           => $rate,
            'gsm'            => 270,
            'availableRolls' => $all_rolls,
            'description'    => 'Premium self-adhesive vinyl, gloss finish. Wt: 270 GSM',
        ),
        array(
            'name'           => 'Sihl 3988 Classic Vinyl PSA Matte',
            'slug'           => 'sihl_3988_classic_vinyl_psa_matte',
            'category'       => 'vinyl_fabric',
            'rate'           => $rate,
            'gsm'            => 0,
            'availableRolls' => $all_rolls,
            'description'    => 'Classic vinyl PSA matte.',
        ),
    );
}

/**
 * Inkjet paper category keys and labels.
 *
 * @return array<string,string>
 */
function fac_get_inkjet_paper_categories() {
    return array(
        'papers'       => 'Papers',
        'canvas'       => 'Canvas',
        'vinyl_fabric' => 'Vinyl & Fabric',
        'other'        => 'Other Choices',
    );
}

/**
 * Resolve default category for a known inkjet paper slug.
 *
 * @param string $slug Paper slug.
 * @return string
 */
function fac_get_default_inkjet_category_for_slug( $slug ) {
    $map = array(
        'artdeco_310g_velvet_textured_bright_white_matte'               => 'papers',
        'artdeco_8_mil_universal_gloss_photo_paper'                     => 'papers',
        'epson_enhanced_matte_inkjet_paper_192'                         => 'papers',
        'epson_metallic_photo_paper_glossy_257'                         => 'papers',
        'epson_premium_luster_photo_260'                                => 'papers',
        'artdeco_17_mil_high_resolution_water_resistant_gloss_canvas'   => 'canvas',
        'artdeco_22_mil_polycotton_water_resistant_matte_canvas'        => 'canvas',
        'artdeco_22_5_mil_canvas_metallic_pearl'                        => 'canvas',
        'sihl_3209_quickstick_aqueous_adhesive_backed_fabric'           => 'vinyl_fabric',
        'sihl_3585_premium_vinyl_sa_270_gloss'                          => 'vinyl_fabric',
        'sihl_3988_classic_vinyl_psa_matte'                             => 'vinyl_fabric',
        'sihl_3148_absolute_clear_film_with_interleaf_paper'            => 'other',
    );

    $slug = sanitize_key( (string) $slug );
    return $map[ $slug ] ?? 'other';
}

/**
 * Sanitize an inkjet paper category key.
 *
 * @param string $category Raw category.
 * @param string $slug     Paper slug used for legacy fallback.
 * @return string
 */
function fac_sanitize_inkjet_category( $category, $slug = '' ) {
    $allowed = array_keys( fac_get_inkjet_paper_categories() );
    $key     = sanitize_key( str_replace( '-', '_', (string) $category ) );

    if ( in_array( $key, $allowed, true ) ) {
        return $key;
    }

    return fac_get_default_inkjet_category_for_slug( $slug );
}
