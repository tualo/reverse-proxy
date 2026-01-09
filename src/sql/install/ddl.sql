delimiter ;

create table if not exists reverse_proxy_public_routes (
    route_path          varchar(255)      not null,
    active              tinyint(1)        not null default 1,
    store_cookies_in_session  tinyint(1)        not null default 0,
    target_url          text             not null,
    allowed_methods     text             null,
    allowed_forward_headers text          null,
    filter_response_headers text          null,
    response_modifier_code text          null,
    primary key (route_path)
) ;
